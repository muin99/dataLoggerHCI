// Home Data Logger — ESP32 firmware
//
// BEFORE FLASHING — checklist:
//   1. This .ino must be the ONLY .ino file in its sketch folder, and the
//      folder must be named exactly the same as this file (minus ".ino").
//      Two .ino files in one folder get compiled together as one program —
//      that's the #1 cause of confusing "redefinition of setup()" errors.
//   2. Board Manager: install the "esp32" board package (Espressif Systems).
//   3. Library Manager: install MFRC522, LiquidCrystal_I2C, and ArduinoJson
//      (v7+). WiFi/WebServer/HTTPClient/WiFiClientSecure/SPI/Wire ship with
//      the esp32 board package — no separate install needed for those.
//   4. Edit `ssid` / `password` below to the WiFi network the ESP32 and the
//      phone (for the camera page) will both be on.
//   5. `apiBase` below already points at the live, tested deployment — only
//      change it if you're testing against a different server.
//   6. Wire the hardware to match the pin map in the next comment block
//      exactly. Touch thresholds further down were carried over from an
//      earlier tested build on the same pins but may still need
//      recalibration on your specific buttons/board.
//
// Pins are fixed by the hardware build (see whattodo.txt). All 5 touch
// buttons are valid ESP32 touch-capable GPIOs (12=T5, 15=T3, 33=T8, 32=T9,
// 13=T4) with no conflicts against the LEDs, buzzer, or RFID's default VSPI
// pins (18/19/23/5, same bus MFRC522 already used).

#include <SPI.h>
#include <MFRC522.h>
#include <Wire.h>
#include <LiquidCrystal_I2C.h>
#include <WiFi.h>
#include <WebServer.h>
#include <HTTPClient.h>
#include <WiFiClientSecure.h>
#include <ArduinoJson.h>
#include "mbedtls/base64.h"
#include "esp_heap_caps.h"

// ---------------- WI-FI CREDENTIALS ----------------
const char* ssid = "M31";
const char* password = "marshm3110w";

// ---------------- BACKEND API CONFIGURATION ----------------
// Points at the live, verified-working deployment. Change this only if
// testing against a different server (works with either http:// or https://).
const char* apiBase = "https://onukrom.xyz/hci/api";
// Must stay comfortably above the server's own worst-case ceiling: ai.php
// retries a slow/failing Hugging Face call up to 3 times at 90s each, and
// capture.php's set_time_limit(290) is the server-side cap for that — this
// needs to wait at least as long, or the ESP32 gives up and reports a
// connection error while the server is still legitimately working.
const int apiTimeoutMs = 310000;

unsigned long lastWifiCheck = 0;
const unsigned long wifiCheckInterval = 5000;

// ---------------- PIN DEFINITIONS (whattodo.txt) ----------------
#define B1_CAMERA_PIN   12   // arm camera
#define B2_CAPTURE_PIN  15   // send capture
#define B3_CANCEL_PIN   33   // cancel / back
#define B4_CONFIRM_PIN  32   // confirm
#define B5_MODIFY_PIN   13   // modify (receipts only)

#define GREEN_LED_PIN   16
#define YELLOW_LED_PIN   2
#define RED_LED_PIN     17
#define BUZZER_PIN      14

#define LCD_SDA         21
#define LCD_SCL         22
#define RFID_RST_PIN     4
#define RFID_SS_PIN      5   // SCK18/MISO19/MOSI23 = default VSPI, no explicit pins needed

// ---------------- TOUCH THRESHOLDS (calibrate on your board) ----------------
// These 5 pins are the same physical pins used in the earlier prototype
// (camerasimulation.ino), just reassigned to B1-B5 — carrying forward that
// build's proven calibration per pin rather than guessing a single value.
const int b1Threshold = 500;   // GPIO12, was CAMERA_MODE_PIN
const int b2Threshold = 500;   // GPIO15, was SCAN_TOUCH_PIN
const int b3Threshold = 679;   // GPIO33, was CANCEL_TOUCH_PIN
const int b4Threshold = 609;   // GPIO32, was CONFIRM_TOUCH_PIN
const int b5Threshold = 500;   // GPIO13, was RFID_MODE_PIN (new role, default threshold)
const unsigned long buttonDebounceMs = 400;

// ---------------- TIMING ----------------
const unsigned long RFID_WINDOW_MS = 10000;         // matches server's DEFAULT_ATTENDANCE_WINDOW_SECONDS
// This is ONLY the on-device "are you still standing here" window, long
// enough to read a scrolling category name + price (e.g. "Milk bottle
// Tk60.00", 20 chars, needs a few seconds to scroll through) and react —
// NOT how long until the object auto-confirms. Walking away without
// pressing anything leaves the object genuinely pending and frees the
// device immediately; the server's own much longer window
// (OBJECT_REVIEW_WINDOW_SECONDS, see api/common.php) is what eventually
// auto-confirms it if nobody resolves it from the dashboard first.
const unsigned long OBJECT_REVIEW_WINDOW_MS = 8000;
const unsigned long SCAN_TIMEOUT_MS = 30000;      // give up waiting for the phone's capture
const unsigned long TRANSIENT_MS = 2200;          // short static confirmations (Saved!, Cancelled) — glanceable, no reading required
const unsigned long ERROR_TRANSIENT_MS = 9000;    // scrolling error messages need real time to read start-to-finish, not a glance
const unsigned long RECEIPT_ABANDON_MS = 60000;   // never auto-confirm — auto-CANCEL only
const unsigned long SCROLL_INTERVAL_MS = 400;
const unsigned long SPINNER_INTERVAL_MS = 250;

// Shared bottom-line legend for both review screens (object and receipt) —
// one wording everywhere the user is choosing between the same 3 buttons.
const char* REVIEW_LEGEND = "3:No 4:OK 5:Mod";

// ---------------- STATE MACHINE ----------------
enum SystemState {
  IDLE,
  CAMERA_ARMED,
  CAMERA_SCANNING,
  OBJECT_REVIEW,
  OBJECT_LOGGED,
  OBJECT_UNKNOWN,
  RECEIPT_REVIEW,
  RFID_PENDING,
  ERROR_RETRY
};
SystemState currentState = IDLE;
SystemState lastRenderedState = (SystemState) -1; // forces first render

const char* stateName(SystemState s) {
  switch (s) {
    case IDLE: return "IDLE";
    case CAMERA_ARMED: return "CAMERA_ARMED";
    case CAMERA_SCANNING: return "CAMERA_SCANNING";
    case OBJECT_REVIEW: return "OBJECT_REVIEW";
    case OBJECT_LOGGED: return "OBJECT_LOGGED";
    case OBJECT_UNKNOWN: return "OBJECT_UNKNOWN";
    case RECEIPT_REVIEW: return "RECEIPT_REVIEW";
    case RFID_PENDING: return "RFID_PENDING";
    case ERROR_RETRY: return "ERROR_RETRY";
    default: return "(boot)";
  }
}

MFRC522 mfrc522(RFID_SS_PIN, RFID_RST_PIN);
LiquidCrystal_I2C lcd(0x27, 16, 2);
WebServer server(80);

// Camera / capture result (written by handleUpload(), consumed once by loop())
volatile bool captureJustCompleted = false;
String captureType = "";      // "object" | "unknown" | "receipt" | "error"
String captureLine1 = "";     // short summary for the transient states
String captureErrorMsg = "";
String receiptScrollText = "";
String pendingReceiptId = "";
float pendingReceiptTotal = 0;
String pendingObjectLogId = "";
bool scanningArmed = false;
unsigned long scanEnteredAt = 0;
unsigned long transientEnteredAt = 0;
unsigned long receiptEnteredAt = 0;
unsigned long objectReviewEnteredAt = 0;
unsigned long lastScrollShift = 0;

// RFID pending state
String rfidHitId = "";
String rfidWorkerName = "";
String rfidEventType = ""; // "check_in" | "check_out" — shown on the LCD so the user knows what they're about to confirm
unsigned long rfidCountdownStart = 0;
int lastRenderedCountdown = -1;

// Button debounce
unsigned long lastB1 = 0, lastB2 = 0, lastB3 = 0, lastB4 = 0, lastB5 = 0;

// ---------------- PHONE CAMERA RELAY PAGE ----------------
// Same phone-as-camera approach as the earlier prototype: the phone opens
// this page over WiFi and streams its rear camera. Polls /status every
// ~400ms and auto-captures the instant B2 arms scanning — no second tap on
// the phone needed ("no action needed in between").
const char HTML_PAGE[] PROGMEM = R"rawliteral(
<!DOCTYPE html>
<html>
<head>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Home Logger Camera</title>
    <style>
        body { font-family: Arial, sans-serif; text-align: center; background: #10160f; color: #eef3ee; margin: 0; padding: 20px; }
        video, canvas { width: 100%; max-width: 400px; border-radius: 8px; background: #000; display: block; margin: 10px auto; }
        button { padding: 15px 30px; font-size: 18px; border: none; background: #4fce93; color: #10160f; font-weight: bold; border-radius: 8px; cursor: pointer; }
        #status { margin-top: 15px; color: #a9b7ad; }
    </style>
</head>
<body>
    <h2>Point at the item, then press B2 on the device</h2>
    <video id="video" autoplay playsinline></video>
    <button id="snap">Or tap to capture manually</button>
    <canvas id="canvas" style="display:none;"></canvas>
    <div id="status">Status: Initializing camera...</div>

    <script>
        const video = document.getElementById('video');
        const canvas = document.getElementById('canvas');
        const snap = document.getElementById('snap');
        const status = document.getElementById('status');
        let sending = false;
        let sentForThisArm = false;

        // Ask for a real resolution explicitly — without constraints some
        // phones default to a low-res stream, which is why the preview used
        // to look hazy.
        navigator.mediaDevices.getUserMedia({ video: { facingMode: "environment", width: { ideal: 1280 }, height: { ideal: 960 } }, audio: false })
            .then(stream => { video.srcObject = stream; status.innerText = "Status: Camera ready. Waiting for B2..."; })
            .catch(err => { status.innerText = "Status Error: " + err; });

        // MAX_EDGE caps the actual captured/uploaded frame, deliberately well
        // below the preview's resolution. 640px already hit an out-of-memory
        // error on this board (confirmed on real hardware — the ESP32 has to
        // hold this image at least twice over in RAM: the base64 text as
        // received, and the decoded+wrapped copy it forwards, plus TLS
        // buffers for the HTTPS request). 480px is a real step down in
        // worst-case memory while still a meaningful jump from the original
        // 320px. If your board has PSRAM and you've confirmed 640px is
        // actually stable after the memory fix in forwardCaptureToApi(),
        // this can be raised again — but verify on-device before trusting it.
        const MAX_EDGE = 480;

        // readyState >= 2 (HAVE_CURRENT_DATA) means the video element has an
        // actual decoded frame to draw. Below that, drawImage() silently
        // paints a blank/black frame instead of throwing — which used to let
        // captureAndSend() race ahead and ship a near-empty JPEG (or, worse,
        // an essentially blank canvas) the instant B2 was pressed right after
        // the camera page loaded, before getUserMedia's stream had actually
        // started delivering frames.
        function captureAndSend(auto) {
            if (sending) return;
            if (video.readyState < 2) {
                // Not consumed as "sent" for this arm cycle — the next
                // /status poll tick (auto) or another tap (manual) will try
                // again once the stream actually has a frame.
                status.innerText = "Status: Camera still starting up, retrying...";
                return;
            }
            sending = true;
            if (auto) sentForThisArm = true;
            status.innerText = "Status: Capturing frame...";
            const vw = video.videoWidth, vh = video.videoHeight;
            const scale = Math.min(1, MAX_EDGE / Math.max(vw, vh));
            const w = Math.round(vw * scale), h = Math.round(vh * scale);
            const context = canvas.getContext('2d');
            canvas.width = w; canvas.height = h;
            context.drawImage(video, 0, 0, w, h);
            const dataUrl = canvas.toDataURL('image/jpeg', 0.85);
            status.innerText = "Status: Uploading & analyzing...";
            fetch('/upload', { method: 'POST', headers: { 'Content-Type': 'text/plain' }, body: dataUrl })
                .then(r => {
                    // A rejected upload (device wasn't ready, or genuinely
                    // lost the body) used to still mark this arm cycle as
                    // "sent," so it just sat there until the device's own
                    // 30s timeout — un-consume it here so the next poll
                    // tick tries again immediately instead.
                    if (!r.ok) { sentForThisArm = false; status.innerText = "Status: Device rejected upload, retrying..."; }
                    else status.innerText = "Status: Sent. Check the device screen.";
                    sending = false;
                })
                .catch(err => { sentForThisArm = false; status.innerText = "Status Error: " + err; sending = false; });
        }
        snap.addEventListener('click', () => captureAndSend(false));

        setInterval(() => {
            fetch('/status').then(r => r.json()).then(data => {
                if (data.scanning) {
                    if (!sentForThisArm) captureAndSend(true);
                } else {
                    sentForThisArm = false;
                }
            }).catch(() => {});
        }, 400);
    </script>
</body>
</html>
)rawliteral";

// ---------------- LED / BUZZER FEEDBACK ----------------
void ledsOff() { digitalWrite(GREEN_LED_PIN, LOW); digitalWrite(YELLOW_LED_PIN, LOW); digitalWrite(RED_LED_PIN, LOW); }
void setSolid(int pin) { ledsOff(); digitalWrite(pin, HIGH); }

bool blinkPhase = false;
unsigned long lastBlinkToggle = 0;
void tickBlink(int pin, unsigned long intervalMs) {
  if (millis() - lastBlinkToggle >= intervalMs) {
    lastBlinkToggle = millis();
    blinkPhase = !blinkPhase;
    digitalWrite(pin, blinkPhase ? HIGH : LOW);
  }
}

// The shared "show a result, then return to idle" states (OBJECT_LOGGED/
// OBJECT_UNKNOWN/ERROR_RETRY) all render through whichever color the
// transition into them picks — this is that handoff. Blinking rather than
// solid so a result visibly *does* something for the ~2s it's on screen,
// instead of just lighting once and sitting static. resetToIdle() clears it.
int transientBlinkPin = -1;
unsigned long transientBlinkIntervalMs = 150;
void beginTransientBlink(int pin, unsigned long intervalMs) {
  ledsOff();
  transientBlinkPin = pin;
  transientBlinkIntervalMs = intervalMs;
  lastBlinkToggle = millis();
  blinkPhase = true;
  digitalWrite(pin, HIGH); // light immediately, don't wait for the first tick
}

// Every distinct event gets its own tone, deliberately different in pitch/
// pattern from every other, so the buzzer alone (without looking at the
// LCD) tells you what kind of thing just happened. Consolidated to exactly
// two outcome patterns (accept / reject) so the buzzer's meaning is
// consistent everywhere: any green outcome always sounds the same, any red
// outcome always sounds the same, regardless of which flow it came from.
void clickFeedback() { tone(BUZZER_PIN, 1200, 25); }                          // any button press, regardless of outcome
void armedTone() { tone(BUZZER_PIN, 1400, 40); }                             // entering a waiting-for-input state (camera armed, scan started)
void cardTone() { tone(BUZZER_PIN, 1700, 45); delay(55); tone(BUZZER_PIN, 2000, 45); } // RFID card physically detected (before the network round-trip)
void greenAcceptFeedback() { tone(BUZZER_PIN, 1900, 650); }                  // "biiiiiiiip" — one long confirming beep, every accepted outcome
void redRejectFeedback() { for (int i = 0; i < 3; i++) { tone(BUZZER_PIN, 850, 90); delay(160); } } // "bip bip bip" — every rejected/error/unknown outcome
void modifyTone() { tone(BUZZER_PIN, 1800, 60); delay(100); tone(BUZZER_PIN, 1800, 60); }     // reserved for the one still-pending (yellow) outcome — neither accepted nor rejected

// ---------------- WIFI / HTTP HELPERS ----------------
// Pulls "onukrom.xyz" out of "https://onukrom.xyz/hci/api" — apiBase is a
// fixed compile-time constant, never user input, so no need to handle
// malformed URLs beyond a plain fallback.
String hostFromUrl(const String& url) {
  int start = url.indexOf("://");
  start = (start >= 0) ? start + 3 : 0;
  int end = url.indexOf('/', start);
  return (end >= 0) ? url.substring(start, end) : url.substring(start);
}

bool beginRequest(HTTPClient& http, WiFiClientSecure& secureClient, const String& url) {
  Serial.println("[HTTP] -> " + url);
  if (url.startsWith("https://")) {
    // NOTE: the older esp32 Arduino core exposed
    // secureClient.setBufferSizes(recv, xmit) to shrink mbedTLS's ~32KB
    // contiguous buffer requirement — the standard fix for the
    // "SSL - Memory allocation failed" error confirmed on real hardware
    // here. This core (3.x, NetworkClientSecure over esp-tls) doesn't
    // expose that call at all, so the actual mitigation lives in
    // forwardCaptureToApi(): connect BEFORE allocating the image buffer,
    // so TLS setup never has to compete with it for the same free block.
    secureClient.setInsecure(); // prototype: skips TLS cert validation, see project notes
    if (!http.begin(secureClient, url)) { Serial.println("[HTTP] begin() failed (https)"); return false; }
    return true;
  }
  if (!http.begin(url)) { Serial.println("[HTTP] begin() failed (http)"); return false; }
  return true;
}

// POST a small JSON body, return true + fill responseDoc on 2xx.
bool postJson(const String& path, const String& jsonBody, JsonDocument& responseDoc, int& httpCode) {
  HTTPClient http;
  WiFiClientSecure secureClient;
  String url = String(apiBase) + path;
  Serial.println("[HTTP] POST body: " + jsonBody);
  if (!beginRequest(http, secureClient, url)) { httpCode = -1; return false; }
  http.setTimeout(apiTimeoutMs);
  http.addHeader("Content-Type", "application/json");
  httpCode = http.POST(jsonBody);
  String response = http.getString();
  if (httpCode < 0) {
    char tlsErr[100];
    int tlsErrCode = secureClient.lastError(tlsErr, sizeof(tlsErr));
    Serial.println("[HTTP] secureClient.lastError() = " + String(tlsErrCode) + ": " + String(tlsErr));
  }
  http.end();
  Serial.println("[HTTP] <- " + String(httpCode) + " " + response);
  if (httpCode < 200 || httpCode >= 300) {
    deserializeJson(responseDoc, response);
    return false;
  }
  DeserializationError err = deserializeJson(responseDoc, response);
  if (err) Serial.println("[HTTP] JSON parse error: " + String(err.c_str()));
  return err == DeserializationError::Ok;
}

// Decodes a base64 dataURL payload and forwards it as multipart/form-data to
// capture.php, parsing the classify/extract result directly into the global
// capture* variables that CAMERA_SCANNING's state handler picks up.
//
// This blocks the whole loop() for as long as the AI call takes (same
// synchronous design as the earlier prototype) — the LCD/LED/buzzer and B3
// go unresponsive for that stretch. Acceptable for a single-user bench
// device; a receipt extraction can genuinely take tens of seconds on a
// free-tier provider, which is why apiTimeoutMs is set so generously.

// Feeds the multipart body to HTTPClient::sendRequest() a few hundred bytes
// at a time instead of needing one ~20-30KB contiguous malloc for the whole
// wrapped image up front. Confirmed on real hardware that connecting first
// (see forwardCaptureToApi()) wasn't enough on its own — TLS setup itself
// consumes/fragments a large enough chunk of the heap that the *subsequent*
// image-buffer malloc then failed instead. Streaming removes that second
// large allocation entirely: the only large one left is TLS's own, which
// doesn't have to share room with anything else at the same time.
class MultipartUploadStream : public Stream {
public:
  MultipartUploadStream(const String& head, const String& base64, size_t decodedLen, const String& tail)
    : _head(head), _base64(base64), _decodedLen(decodedLen), _tail(tail) {
    _total = _head.length() + _decodedLen + _tail.length();
  }
  int available() override { return (int)(_total - _delivered); }
  int read() override { uint8_t b; return readBytes(&b, 1) == 1 ? (int) b : -1; }
  int peek() override { return -1; } // never called by HTTPClient's send loop
  size_t write(uint8_t) override { return 0; } // write-side unused — this stream is upload-only
  size_t readBytes(uint8_t* buf, size_t len) override {
    size_t written = 0;
    while (written < len && _delivered < _total) {
      if (_headPos < _head.length()) {
        size_t n = min(len - written, _head.length() - _headPos);
        memcpy(buf + written, _head.c_str() + _headPos, n);
        _headPos += n; written += n; _delivered += n;
        continue;
      }
      size_t imageDelivered = _delivered - _head.length();
      if (imageDelivered < _decodedLen) {
        if (_scratchPos >= _scratchLen) refillScratch();
        if (_scratchLen == 0) break; // decode error — nothing more to give
        size_t n = min(len - written, _scratchLen - _scratchPos);
        memcpy(buf + written, _scratch + _scratchPos, n);
        _scratchPos += n; written += n; _delivered += n;
        continue;
      }
      size_t tailDelivered = imageDelivered - _decodedLen;
      size_t n = min(len - written, _tail.length() - tailDelivered);
      memcpy(buf + written, _tail.c_str() + tailDelivered, n);
      written += n; _delivered += n;
    }
    return written;
  }
private:
  // Base64 decodes in independent 4-char -> 3-byte groups, so any
  // 4-char-aligned slice can be decoded on its own — no need to see the
  // whole string. 1024 chars (a multiple of 4) in, up to 768 bytes out.
  void refillScratch() {
    size_t remaining = _base64.length() - _base64Pos;
    size_t chunkChars = min(remaining, (size_t) 1024);
    size_t olen = 0;
    mbedtls_base64_decode(_scratch, sizeof(_scratch), &olen, (const unsigned char*) (_base64.c_str() + _base64Pos), chunkChars);
    _base64Pos += chunkChars;
    _scratchLen = olen;
    _scratchPos = 0;
  }
  const String& _head; const String& _base64; const String& _tail;
  size_t _decodedLen, _total, _delivered = 0, _headPos = 0, _base64Pos = 0;
  uint8_t _scratch[768]; size_t _scratchLen = 0, _scratchPos = 0;
};

void forwardCaptureToApi(const String& base64Data) {
  Serial.println("[CAPTURE] received " + String(base64Data.length()) + " base64 chars from phone, free heap=" + String(ESP.getFreeHeap()) + ", largest free block=" + String(heap_caps_get_largest_free_block(MALLOC_CAP_8BIT)));
  size_t decodedLen = 0;
  mbedtls_base64_decode(NULL, 0, &decodedLen, (const unsigned char*)base64Data.c_str(), base64Data.length());
  if (decodedLen == 0) { Serial.println("[CAPTURE] empty payload"); captureType = "error"; captureErrorMsg = "Empty image payload"; captureJustCompleted = true; return; }

  // Connect BEFORE allocating the (tens-of-KB) image buffer below, while the
  // heap is in its normal, least-pressured state. Confirmed on real
  // hardware that connecting AFTER that allocation can fail outright
  // (secureClient.lastError() = -32512, "SSL - Memory allocation failed") —
  // mbedTLS's own session setup needs a sizeable contiguous block, and a
  // plain ESP32's heap fragments enough over uptime that adding a fresh
  // 20-30KB image buffer into the mix right at that moment can be what
  // tips it over, even when total free heap still looks comfortable.
  // Establishing the TLS session first and only decoding into memory
  // afterward keeps these two allocations from ever competing at once.
  HTTPClient http;
  WiFiClientSecure secureClient;
  String url = String(apiBase) + "/device/capture.php";
  if (!beginRequest(http, secureClient, url)) {
    captureType = "error"; captureErrorMsg = "Connect failed"; captureJustCompleted = true;
    return;
  }
  if (!secureClient.connect(hostFromUrl(url).c_str(), 443)) {
    char tlsErr[100];
    int tlsErrCode = secureClient.lastError(tlsErr, sizeof(tlsErr));
    Serial.println("[CAPTURE] pre-connect failed, secureClient.lastError() = " + String(tlsErrCode) + ": " + String(tlsErr) + ", free heap=" + String(ESP.getFreeHeap()) + ", largest free block=" + String(heap_caps_get_largest_free_block(MALLOC_CAP_8BIT)));
    captureType = "error"; captureErrorMsg = "Connect failed"; captureJustCompleted = true;
    return;
  }

  const String boundary = "HomeLoggerBoundary9f31";
  String head = "--" + boundary + "\r\n";
  head += "Content-Disposition: form-data; name=\"image\"; filename=\"capture.jpg\"\r\n";
  head += "Content-Type: image/jpeg\r\n\r\n";
  String tail = "\r\n--" + boundary + "--\r\n";
  size_t totalLen = head.length() + decodedLen + tail.length();
  Serial.println("[CAPTURE] streaming " + String(decodedLen) + " JPEG bytes to capture.php (this can take up to ~90s for a receipt), free heap=" + String(ESP.getFreeHeap()));

  MultipartUploadStream bodyStream(head, base64Data, decodedLen, tail);
  http.setTimeout(apiTimeoutMs);
  http.addHeader("Content-Type", "multipart/form-data; boundary=" + boundary);
  unsigned long requestStart = millis();
  int httpCode = http.sendRequest("POST", &bodyStream, totalLen);
  unsigned long elapsedMs = millis() - requestStart;
  String response = http.getString();
  // A negative httpCode means POST() never got a real HTTP response at all —
  // the failure happened during connect()/TLS handshake. secureClient's own
  // mbedTLS error (if any) says WHICH failure: a nonzero code here means TLS
  // itself was rejected/reset (consistent with an edge proxy actively
  // refusing the handshake); a zero code with httpCode<0 means the plain TCP
  // connect never even got that far (DNS, routing, or local socket issue).
  if (httpCode < 0) {
    char tlsErr[100];
    int tlsErrCode = secureClient.lastError(tlsErr, sizeof(tlsErr));
    Serial.println("[CAPTURE] secureClient.lastError() = " + String(tlsErrCode) + ": " + String(tlsErr));
  }
  http.end();
  Serial.println("[CAPTURE] response after " + String(elapsedMs / 1000.0, 1) + "s, HTTP " + String(httpCode) + ":");
  Serial.println(response.length() > 0 ? response : "(empty body)");

  if (httpCode < 200 || httpCode >= 300) {
    JsonDocument errDoc;
    DeserializationError parseErr = deserializeJson(errDoc, response);
    captureType = "error";
    // ArduinoJson v7's `|` default must be a plain default-constructible
    // type — a concatenated String (StringSumHelper) doesn't qualify, so
    // build the fallback separately instead of inline.
    String fallbackMsg = "HTTP " + String(httpCode);
    if (parseErr) {
      // No parseable JSON body at all — this is a server/proxy-level error
      // (e.g. a host's execution-time limit killing the PHP process), not
      // one of capture.php's own JSON error responses.
      Serial.println("[CAPTURE] response wasn't JSON (likely a server/proxy timeout, not a PHP-level error)");
      captureErrorMsg = fallbackMsg;
    } else {
      captureErrorMsg = errDoc["error"] | fallbackMsg;
    }
    captureJustCompleted = true;
    return;
  }

  JsonDocument doc;
  DeserializationError err = deserializeJson(doc, response);
  if (err) { Serial.println("[CAPTURE] JSON parse error: " + String(err.c_str())); captureType = "error"; captureErrorMsg = "Bad response"; captureJustCompleted = true; return; }

  captureType = doc["type"] | "error";
  if (captureType == "object") {
    pendingObjectLogId = doc["log_id"] | "";
    String name = doc["name"] | "Item";
    float total = doc["total"] | 0.0;
    captureLine1 = name;
    char buf[24]; snprintf(buf, sizeof(buf), " Tk%.2f", total);
    captureLine1 += buf;
  } else if (captureType == "unknown") {
    captureLine1 = "Not recognized";
  } else if (captureType == "receipt") {
    pendingReceiptId = doc["receipt_id"] | "";
    pendingReceiptTotal = doc["total"] | 0.0;
    String merchant = doc["merchant"] | "Receipt";
    JsonArray items = doc["items"].as<JsonArray>();
    String scroll = String(items.size()) + " items: ";
    for (JsonObject item : items) {
      String n = item["name"] | "item";
      float lt = item["line_total"] | 0.0;
      char buf[48]; snprintf(buf, sizeof(buf), "%s Tk%.0f | ", n.c_str(), lt);
      scroll += buf;
    }
    char totalBuf[24]; snprintf(totalBuf, sizeof(totalBuf), "TOTAL Tk%.2f     ", pendingReceiptTotal);
    scroll += totalBuf;
    // Cap to the display's safe DDRAM width so scrollDisplayLeft() never
    // walks into undefined territory on a long item list.
    receiptScrollText = scroll.substring(0, 39);
  } else {
    captureErrorMsg = doc["message"] | "Unexpected response";
  }
  Serial.println("[CAPTURE] result type=" + captureType);
  captureJustCompleted = true;
}

// ---------------- WEB SERVER HANDLERS ----------------
// Without an explicit no-cache header, phone browsers can keep serving an
// old cached copy of this page (JS and all) after the ESP32 gets reflashed
// with fixes to it — the device would then be running new firmware while
// the phone's open tab is silently still running stale JS, indistinguishable
// from a real bug without checking response headers.
void handleRoot() {
  server.sendHeader("Cache-Control", "no-store");
  server.send_P(200, "text/html", HTML_PAGE);
}

void handleStatus() {
  JsonDocument doc;
  doc["scanning"] = scanningArmed;
  String out; serializeJson(doc, out);
  server.send(200, "application/json", out);
}

// Receives the phone's upload body ourselves via WebServer's "raw" callback
// mechanism instead of its default server.arg("plain") path. Confirmed on
// real hardware that the default path can fail silently: internally it
// assembles the full body via repeated malloc()+realloc() as ~1.4KB chunks
// arrive (Parsing.cpp's readBytesWithTimeout), and if any one of those
// reallocs fails under heap fragmentation, arg("plain") just comes back
// empty — no error, no indication why — even with a perfectly valid
// Content-Length (confirmed: Content-Length=50299 in the log, body still
// empty). Doing ONE malloc of the exact size up front (known from
// Content-Length, already available via collectHeaders() in setup()) avoids
// the repeated grow-and-copy entirely — either that one allocation succeeds
// or we know immediately and can say so, instead of silently losing data.
uint8_t* uploadRawBuf = nullptr;
size_t uploadRawLen = 0, uploadRawCapacity = 0;
bool uploadRawFailed = false;

void handleUploadRaw() {
  HTTPRaw& raw = server.raw();
  if (raw.status == RAW_START) {
    free(uploadRawBuf); uploadRawBuf = nullptr; uploadRawLen = 0; uploadRawFailed = false;
    uploadRawCapacity = (size_t) server.header("Content-Length").toInt();
    if (uploadRawCapacity == 0) { uploadRawFailed = true; return; }
    uploadRawBuf = (uint8_t*) malloc(uploadRawCapacity);
    if (!uploadRawBuf) {
      Serial.println("[PHONE] raw upload malloc failed for " + String(uploadRawCapacity) + " bytes, free heap=" + String(ESP.getFreeHeap()));
      uploadRawFailed = true;
    }
  } else if (raw.status == RAW_WRITE) {
    if (uploadRawBuf && !uploadRawFailed && uploadRawLen + raw.currentSize <= uploadRawCapacity) {
      memcpy(uploadRawBuf + uploadRawLen, raw.buf, raw.currentSize);
      uploadRawLen += raw.currentSize;
    }
  } else if (raw.status == RAW_ABORTED) {
    Serial.println("[PHONE] raw upload aborted after " + String(uploadRawLen) + " of " + String(uploadRawCapacity) + " bytes");
    free(uploadRawBuf); uploadRawBuf = nullptr; uploadRawFailed = true;
  }
  // RAW_END: nothing to do here — handleUpload() (the main registered fn)
  // runs immediately after and reads whatever ended up accumulated.
}

void handleUpload() {
  Serial.println("[PHONE] /upload hit, state=" + String((int) currentState));
  if (currentState != CAMERA_SCANNING) {
    Serial.println("[PHONE] rejected: not in CAMERA_SCANNING");
    server.send(400, "text/plain", "Not scanning.");
    free(uploadRawBuf); uploadRawBuf = nullptr;
    return;
  }
  if (uploadRawFailed || !uploadRawBuf || uploadRawLen == 0) {
    Serial.println("[PHONE] rejected: raw body reception failed (failed=" + String(uploadRawFailed) + ", received=" + String(uploadRawLen) + " of " + String(uploadRawCapacity) + " bytes, free heap=" + String(ESP.getFreeHeap()) + ")");
    server.send(400, "text/plain", "No payload.");
    free(uploadRawBuf); uploadRawBuf = nullptr;
    return;
  }
  String body((char*) uploadRawBuf, uploadRawLen);
  free(uploadRawBuf); uploadRawBuf = nullptr;
  int commaIndex = body.indexOf(',');
  String base64Data = (commaIndex >= 0) ? body.substring(commaIndex + 1) : body;
  server.send(200, "text/plain", "OK");
  scanningArmed = false;
  forwardCaptureToApi(base64Data);
}

// ---------------- RFID ----------------
void startRfidFlow(const String& uid) {
  Serial.println("[RFID] card read: " + uid);
  JsonDocument body; body["uid"] = uid;
  String payload; serializeJson(body, payload);
  JsonDocument response; int httpCode = 0;
  bool ok = postJson("/device/rfid.php", payload, response, httpCode);

  if (httpCode == 404) {
    Serial.println("[RFID] unregistered tag");
    lcdTwoLines("Unknown card", "Check the app");
    beginTransientBlink(RED_LED_PIN, 120); redRejectFeedback();
    currentState = OBJECT_UNKNOWN; // reuse the same transient/auto-return behavior
    transientEnteredAt = millis();
    return;
  }
  if (!ok) {
    // Was previously computing this message but never actually displaying
    // or sounding anything for it — a real network/server failure sat
    // silent on-screen with no feedback at all.
    Serial.println("[RFID] service error, httpCode=" + String(httpCode));
    lcdTwoLines("Card read error", "Try again");
    beginTransientBlink(RED_LED_PIN, 120); redRejectFeedback();
    currentState = ERROR_RETRY; transientEnteredAt = millis();
    return;
  }
  rfidHitId = response["attendance"]["hit_id"] | "";
  rfidWorkerName = response["attendance"]["worker"] | "Worker";
  rfidEventType = response["attendance"]["event_type"] | "check_in";
  Serial.println("[RFID] matched: " + rfidWorkerName + " (" + rfidEventType + ", hit_id=" + rfidHitId + ")");
  rfidCountdownStart = millis();
  lastRenderedCountdown = -1;
  currentState = RFID_PENDING;
}

void resolveAttendance(const String& action) {
  Serial.println("[RFID] resolving " + rfidHitId + " -> " + action);
  JsonDocument body; body["hit_id"] = rfidHitId; body["action"] = action;
  String payload; serializeJson(body, payload);
  JsonDocument response; int httpCode = 0;
  postJson("/device/resolve_attendance.php", payload, response, httpCode);
}

// ---------------- RECEIPT RESOLVE ----------------
// Returns true if the server downgraded a "confirm" to needs_review because
// the receipt's own numbers didn't add up (subtotal-discount+tax != total)
// — the LCD can't show that discrepancy before the button press, so the
// caller uses this to tell the user honestly what actually happened.
bool resolveReceipt(const String& action) {
  Serial.println("[RECEIPT] resolving " + pendingReceiptId + " -> " + action);
  JsonDocument body; body["receipt_id"] = pendingReceiptId; body["action"] = action;
  String payload; serializeJson(body, payload);
  JsonDocument response; int httpCode = 0;
  postJson("/device/resolve_receipt.php", payload, response, httpCode);
  return response["downgraded"] | false;
}

// ---------------- OBJECT RESOLVE ----------------
void resolveObject(const String& action) {
  Serial.println("[OBJECT] resolving " + pendingObjectLogId + " -> " + action);
  JsonDocument body; body["log_id"] = pendingObjectLogId; body["action"] = action;
  String payload; serializeJson(body, payload);
  JsonDocument response; int httpCode = 0;
  postJson("/device/resolve_object.php", payload, response, httpCode);
}

// ---------------- BUTTON HELPERS ----------------
bool pressed(int pin, int threshold, unsigned long& lastTrigger) {
  int reading = touchRead(pin);
  if (reading < threshold && millis() - lastTrigger > buttonDebounceMs) {
    lastTrigger = millis();
    clickFeedback();
    Serial.println("[BTN] pin " + String(pin) + " pressed (reading=" + String(reading) + ", threshold=" + String(threshold) + ")");
    return true;
  }
  return false;
}

void resetToIdle() {
  currentState = IDLE;
  scanningArmed = false;
  transientBlinkPin = -1;
  ledsOff();
}

// Any plain two-line render cancels whatever scrolling session might still
// be active (see startScrollingText/tickScrollingText below) — otherwise a
// stale scroll from a previous screen could keep silently advancing row 0
// underneath a caller that thinks it's showing static text.
String scrollSourceText = "";
// How long the transient-message bucket (OBJECT_LOGGED/OBJECT_UNKNOWN/
// ERROR_RETRY) holds the current screen before auto-returning to idle.
// Resets to the short "glanceable" default on every plain render; a
// transition that needs more reading time (even if its text is short
// enough not to need scrolling) overrides this right after calling
// lcdTwoLines(), before entering that state.
unsigned long transientHoldMs = TRANSIENT_MS;

void lcdTwoLines(const String& l1, const String& l2) {
  scrollSourceText = "";
  transientHoldMs = TRANSIENT_MS;
  lcd.clear();
  lcd.setCursor(0, 0); lcd.print(l1.substring(0, 16));
  lcd.setCursor(0, 1); lcd.print(l2.substring(0, 16));
}

// Scrolls row 0 through arbitrarily long text while row 1 (the button
// legend) stays completely fixed. Deliberately NOT using the LCD
// controller's built-in scrollDisplayLeft() — that shifts BOTH rows
// together, which would eventually scroll the row 1 legend off-screen too
// (confirmed: with a long receipt item list at the old 400ms tick rate, the
// legend disappeared after under 10 seconds — well within a real decision
// window). Call tickScrollingText() once per loop() to advance it.
int scrollOffset = 0;
void startScrollingText(const String& text, const String& promptLine) {
  scrollSourceText = text.length() > 16 ? text + "     " : text; // trailing gap before it loops, only if it needs to scroll at all
  scrollOffset = 0;
  lcd.clear();
  lcd.setCursor(0, 0); lcd.print(scrollSourceText.substring(0, 16));
  lcd.setCursor(0, 1); lcd.print(promptLine.substring(0, 16));
  lastScrollShift = millis();
}

void tickScrollingText() {
  if (scrollSourceText.length() <= 16) return; // fits already, nothing to scroll
  if (millis() - lastScrollShift < SCROLL_INTERVAL_MS) return;
  lastScrollShift = millis();
  scrollOffset = (scrollOffset + 1) % scrollSourceText.length();
  String window = scrollSourceText.substring(scrollOffset) + scrollSourceText.substring(0, scrollOffset);
  lcd.setCursor(0, 0);
  lcd.print(window.substring(0, 16));
}

// ---------------- SETUP ----------------
void setup() {
  Serial.begin(115200);
  delay(200);
  Serial.println();
  Serial.println("=== Home Data Logger booting ===");
  Serial.println("API base: " + String(apiBase));
  Serial.println("WiFi SSID: " + String(ssid));

  pinMode(GREEN_LED_PIN, OUTPUT);
  pinMode(YELLOW_LED_PIN, OUTPUT);
  pinMode(RED_LED_PIN, OUTPUT);
  pinMode(BUZZER_PIN, OUTPUT);
  ledsOff();
  tone(BUZZER_PIN, 2000, 100);

  Wire.begin(LCD_SDA, LCD_SCL);
  lcd.init();
  lcd.backlight();

  lcdTwoLines("Connecting WiFi", "");
  WiFi.begin(ssid, password);
  while (WiFi.status() != WL_CONNECTED) { delay(400); Serial.print("."); }
  Serial.println("\nWiFi connected: " + WiFi.localIP().toString());
  lcdTwoLines("Camera page:", WiFi.localIP().toString());
  delay(3000);

  // WebServer only exposes headers you've explicitly asked it to collect —
  // without this, server.header()/headers() silently return empty/zero
  // regardless of what the client actually sent, making any diagnostic
  // built on them meaningless. Needed to actually see what's going on in
  // handleUpload()'s empty-payload rejection path.
  const char* collectedHeaders[] = { "Content-Length", "Content-Type", "Transfer-Encoding" };
  server.collectHeaders(collectedHeaders, 3);
  server.on("/", handleRoot);
  server.on("/status", handleStatus);
  server.on("/upload", HTTP_POST, handleUpload, handleUploadRaw);
  server.begin();

  SPI.begin();
  mfrc522.PCD_Init();
  mfrc522.PCD_SetAntennaGain(mfrc522.RxGain_max);

  randomSeed(analogRead(34));
  Serial.println("System ready.");
  resetToIdle();
}

// ---------------- MAIN LOOP ----------------
void loop() {
  server.handleClient();

  if (millis() - lastWifiCheck > wifiCheckInterval) {
    lastWifiCheck = millis();
    if (WiFi.status() != WL_CONNECTED) { WiFi.disconnect(); WiFi.begin(ssid, password); }
  }
  if (WiFi.status() != WL_CONNECTED) {
    tickBlink(RED_LED_PIN, 600);
    if (lastRenderedState != (SystemState) -2) { lcdTwoLines("WiFi lost...", "Reconnecting"); redRejectFeedback(); lastRenderedState = (SystemState) -2; }
    delay(10);
    return;
  }

  bool p1 = pressed(B1_CAMERA_PIN, b1Threshold, lastB1);
  bool p2 = pressed(B2_CAPTURE_PIN, b2Threshold, lastB2);
  bool p3 = pressed(B3_CANCEL_PIN, b3Threshold, lastB3);
  bool p4 = pressed(B4_CONFIRM_PIN, b4Threshold, lastB4);
  bool p5 = pressed(B5_MODIFY_PIN, b5Threshold, lastB5);

  switch (currentState) {

    case IDLE: {
      if (lastRenderedState != IDLE) { lcdTwoLines("Ready", "1:Log   Tap card"); ledsOff(); lastRenderedState = IDLE; }
      if (mfrc522.PICC_IsNewCardPresent() && mfrc522.PICC_ReadCardSerial()) {
        cardTone(); // immediate ack the instant the tap is physically sensed — don't make them wait for the network round-trip to know it was seen
        String uid = "";
        for (byte i = 0; i < mfrc522.uid.size; i++) {
          if (mfrc522.uid.uidByte[i] < 0x10) uid += "0";
          uid += String(mfrc522.uid.uidByte[i], HEX);
        }
        uid.toUpperCase();
        mfrc522.PICC_HaltA();
        mfrc522.PCD_StopCrypto1();
        startRfidFlow(uid);
        lastRenderedState = (SystemState) -1;
        break;
      }
      if (p1) { currentState = CAMERA_ARMED; lastRenderedState = (SystemState) -1; }
      break;
    }

    case CAMERA_ARMED: {
      if (lastRenderedState != CAMERA_ARMED) { lcdTwoLines("Place item", "2:Send  3:Back"); ledsOff(); armedTone(); lastRenderedState = CAMERA_ARMED; }
      if (p3) { resetToIdle(); lastRenderedState = (SystemState) -1; break; }
      if (p2) {
        scanningArmed = true;
        scanEnteredAt = millis();
        currentState = CAMERA_SCANNING;
        lastRenderedState = (SystemState) -1;
      }
      break;
    }

    case CAMERA_SCANNING: {
      if (lastRenderedState != CAMERA_SCANNING) { lcdTwoLines("Analyzing photo", ""); armedTone(); lastRenderedState = CAMERA_SCANNING; }
      tickBlink(YELLOW_LED_PIN, 300);

      // A real receipt can take tens of seconds server-side — a static
      // "analyzing..." with no progress cue over that long looks frozen.
      // Spinner + elapsed seconds, rewritten in place (no lcd.clear()) so it
      // doesn't flicker on every 250ms tick.
      static unsigned long lastSpinnerTick = 0;
      static int spinnerIndex = 0;
      if (millis() - lastSpinnerTick > SPINNER_INTERVAL_MS) {
        lastSpinnerTick = millis();
        const char spinnerChars[] = { '|', '/', '-', '\\' };
        spinnerIndex = (spinnerIndex + 1) % 4;
        int elapsedSec = (int) ((millis() - scanEnteredAt) / 1000);
        String line2 = String(spinnerChars[spinnerIndex]) + " Working " + String(elapsedSec) + "s";
        while (line2.length() < 16) line2 += ' ';
        lcd.setCursor(0, 1);
        lcd.print(line2.substring(0, 16));
      }

      if (captureJustCompleted) {
        captureJustCompleted = false;
        if (captureType == "object") {
          currentState = OBJECT_REVIEW; objectReviewEnteredAt = millis(); lastScrollShift = millis();
          setSolid(YELLOW_LED_PIN);
          startScrollingText(captureLine1, REVIEW_LEGEND);
        } else if (captureType == "unknown") {
          currentState = OBJECT_UNKNOWN; transientEnteredAt = millis();
          beginTransientBlink(RED_LED_PIN, 120); redRejectFeedback();
          lcdTwoLines("Not recognized", "Check the app");
        } else if (captureType == "receipt") {
          currentState = RECEIPT_REVIEW; receiptEnteredAt = millis(); lastScrollShift = millis();
          setSolid(YELLOW_LED_PIN);
          startScrollingText(receiptScrollText, REVIEW_LEGEND);
        } else {
          currentState = ERROR_RETRY; transientEnteredAt = millis();
          beginTransientBlink(RED_LED_PIN, 120); redRejectFeedback();
          startScrollingText("Error: " + captureErrorMsg, "3 or wait...   ");
        }
        lastRenderedState = (SystemState) -1;
        break;
      }
      if (millis() - scanEnteredAt > SCAN_TIMEOUT_MS) {
        scanningArmed = false;
        currentState = ERROR_RETRY; transientEnteredAt = millis();
        captureErrorMsg = "Timed out";
        beginTransientBlink(RED_LED_PIN, 120); redRejectFeedback();
        lcdTwoLines("No response", "Try again");
        lastRenderedState = ERROR_RETRY;
        break;
      }
      if (p3) { scanningArmed = false; resetToIdle(); lastRenderedState = (SystemState) -1; }
      break;
    }

    // These three double as the generic "show a result message briefly, then
    // return to idle" states for every flow (object/unknown captures, and
    // RFID/receipt outcomes reuse them too) — the LCD/LED/tone for the
    // specific message is set once at the transition point that enters this
    // state; this handler only ever waits it out or lets B3 skip the wait.
    case OBJECT_LOGGED:
    case OBJECT_UNKNOWN:
    case ERROR_RETRY: {
      tickScrollingText(); // no-op unless ERROR_RETRY's message needed scrolling
      if (transientBlinkPin >= 0) tickBlink(transientBlinkPin, transientBlinkIntervalMs);
      // A scrolling error message needs real time to read, not a glance —
      // everything else here is a short static confirmation, unless the
      // transition explicitly asked for more (transientHoldMs).
      unsigned long holdTime = scrollSourceText.length() > 16 ? ERROR_TRANSIENT_MS : transientHoldMs;
      if (p3 || millis() - transientEnteredAt > holdTime) { resetToIdle(); lastRenderedState = (SystemState) -1; }
      break;
    }

    // Short review window (OBJECT_REVIEW_WINDOW_MS): unlike receipts, objects auto-CONFIRM on
    // silence (routine/low-stakes — see OBJECT_REVIEW_WINDOW_MS), not cancel.
    case OBJECT_REVIEW: {
      tickScrollingText();
      tickBlink(YELLOW_LED_PIN, 200); // faster than the receipt's solid yellow — signals "time-limited"
      if (p3) { resolveObject("reject"); beginTransientBlink(RED_LED_PIN, 120); redRejectFeedback(); lcdTwoLines("Cancelled", ""); currentState = ERROR_RETRY; transientEnteredAt = millis(); captureErrorMsg = ""; lastRenderedState = ERROR_RETRY; break; }
      if (p4) { resolveObject("confirm"); beginTransientBlink(GREEN_LED_PIN, 200); greenAcceptFeedback(); lcdTwoLines("Saved!", ""); currentState = OBJECT_LOGGED; transientEnteredAt = millis(); lastRenderedState = OBJECT_LOGGED; break; }
      if (p5) { resolveObject("flag_modify"); beginTransientBlink(GREEN_LED_PIN, 200); greenAcceptFeedback(); lcdTwoLines("Saved.", "Check the app"); currentState = OBJECT_LOGGED; transientEnteredAt = millis(); lastRenderedState = OBJECT_LOGGED; break; }
      if (millis() - objectReviewEnteredAt >= OBJECT_REVIEW_WINDOW_MS) {
        // Deliberately NOT resolving anything here — this is just the short
        // "are you still standing here" window. Walking away leaves it
        // genuinely pending: the device frees up immediately for the next
        // scan or RFID tap, and the server's own (much longer) confirm
        // window is what eventually auto-confirms it if nobody acts on it
        // from the dashboard first.
        beginTransientBlink(YELLOW_LED_PIN, 200); modifyTone();
        lcdTwoLines("Saved as pending", "Check app: 1hr");
        currentState = OBJECT_LOGGED; transientEnteredAt = millis(); lastRenderedState = OBJECT_LOGGED;
      }
      break;
    }

    case RECEIPT_REVIEW: {
      tickScrollingText();
      if (p3) { resolveReceipt("reject"); beginTransientBlink(RED_LED_PIN, 120); redRejectFeedback(); lcdTwoLines("Cancelled", ""); currentState = ERROR_RETRY; transientEnteredAt = millis(); captureErrorMsg = ""; lastRenderedState = ERROR_RETRY; break; }
      if (p4) {
        bool downgraded = resolveReceipt("confirm");
        if (downgraded) {
          // Server caught the numbers not adding up — this is NOT a plain
          // save, say so honestly instead of claiming unconditional success.
          beginTransientBlink(RED_LED_PIN, 120); redRejectFeedback();
          lcdTwoLines("Numbers don't", "Recheck in app");
          transientHoldMs = ERROR_TRANSIENT_MS; // needs real reading time, not a glance
        } else {
          beginTransientBlink(GREEN_LED_PIN, 200); greenAcceptFeedback();
          lcdTwoLines("Saved!", "");
        }
        currentState = OBJECT_LOGGED; transientEnteredAt = millis(); lastRenderedState = OBJECT_LOGGED;
        break;
      }
      if (p5) { resolveReceipt("flag_modify"); beginTransientBlink(GREEN_LED_PIN, 200); greenAcceptFeedback(); lcdTwoLines("Saved.", "Check the app"); currentState = OBJECT_LOGGED; transientEnteredAt = millis(); lastRenderedState = OBJECT_LOGGED; break; }
      if (millis() - receiptEnteredAt > RECEIPT_ABANDON_MS) {
        // Abandoned: never auto-confirm financial data, always cancel.
        resolveReceipt("reject");
        beginTransientBlink(RED_LED_PIN, 120); redRejectFeedback();
        lcdTwoLines("Cancelled", "(no response)");
        currentState = ERROR_RETRY; transientEnteredAt = millis(); captureErrorMsg = "";
        lastRenderedState = ERROR_RETRY;
      }
      break;
    }

    case RFID_PENDING: {
      int secondsLeft = max(0, (int) (RFID_WINDOW_MS - (millis() - rfidCountdownStart)) / 1000);
      if (secondsLeft != lastRenderedCountdown) {
        lastRenderedCountdown = secondsLeft;
        // Says which direction this is (check-in vs check-out) — previously
        // showed just the name, leaving the user to guess what they were
        // about to confirm or cancel.
        String direction = rfidEventType == "check_out" ? "OUT" : "IN";
        lcdTwoLines(rfidWorkerName + " - " + direction, "3:No | auto:" + String(secondsLeft) + "s");
      }
      tickBlink(YELLOW_LED_PIN, 500);
      if (p3) {
        resolveAttendance("reject");
        beginTransientBlink(RED_LED_PIN, 120); redRejectFeedback();
        lcdTwoLines("Cancelled", "");
        currentState = OBJECT_LOGGED; transientEnteredAt = millis(); lastRenderedState = OBJECT_LOGGED;
        break;
      }
      if (millis() - rfidCountdownStart >= RFID_WINDOW_MS) {
        resolveAttendance("confirm");
        beginTransientBlink(GREEN_LED_PIN, 200); greenAcceptFeedback();
        lcdTwoLines("Logged!", rfidWorkerName);
        currentState = OBJECT_LOGGED; transientEnteredAt = millis(); lastRenderedState = OBJECT_LOGGED;
      }
      break;
    }
  }

  // Single centralized transition log — catches every state change exactly
  // once, regardless of which branch above caused it, so nothing gets missed.
  static SystemState previousLoggedState = (SystemState) -1;
  if (currentState != previousLoggedState) {
    Serial.println("[STATE] " + String(stateName(previousLoggedState)) + " -> " + String(stateName(currentState)));
    previousLoggedState = currentState;
  }

  delay(10);
}
