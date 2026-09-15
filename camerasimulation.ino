#include <SPI.h>
#include <MFRC522.h>
#include <Wire.h>
#include <LiquidCrystal_I2C.h>
#include <WiFi.h>
#include <WebServer.h>
#include <HTTPClient.h>
#include <WiFiClientSecure.h>
#include "mbedtls/base64.h"

// --- WI-FI CREDENTIALS ---
const char* ssid = "M31";
const char* password = "marshm3110w";

// --- BACKEND API CONFIGURATION ---
// The PHP endpoint that actually stores the image (see upload.php).
const char* apiUrl = "https://onukrom.xyz/hci/upload.php";
const int apiTimeoutMs = 15000;

// --- WI-FI RELIABILITY ---
unsigned long lastWifiCheck = 0;
const unsigned long wifiCheckInterval = 5000; // re-check connection every 5s

// --- PIN DEFINITIONS ---
#define RST_PIN             4    
#define SS_PIN              5    
#define LCD_SDA            21   
#define LCD_SCL            22   

#define CONFIRM_TOUCH_PIN  32  
#define CANCEL_TOUCH_PIN   33  // back  button
#define CAMERA_MODE_PIN    12  
#define RFID_MODE_PIN      13  
#define SCAN_TOUCH_PIN     15   

#define GREEN_LED_PIN      16  
#define RED_LED_PIN       17  
#define BUZZER_PIN         14  

// --- CALIBRATED TOUCH THRESHOLDS ---
const int confirmThreshold = 609;
const int cancelThreshold = 679;
const int cameraModeThreshold = 500; 
const int rfidModeThreshold = 500;   
const int scanThreshold = 500;       

// --- SYSTEM STATE ENGINE ---
enum SystemState {
  MODE_MENU,
  MODE_CAMERA,     // "Scan Object?" screen (Waiting for Pin 15)
  STATE_SCANNING,  // Waiting for phone image payload
  STATE_DISPLAY_OBJ, // Showing object / waiting for Confirm or Cancel
  MODE_RFID
};
SystemState currentState = MODE_MENU;

// Objects
MFRC522 mfrc522(SS_PIN, RST_PIN);
LiquidCrystal_I2C lcd(0x27, 16, 2);
WebServer server(80); // <-- NEW: Initialize web server on default HTTP port 80

// Camera Variables
String recognizedObjects[] = {"Milk            ", "Newspaper       ", "Gas Cylinder    ", "Receipt         "};
String selectedObject = "";
bool objectDetected = false;
volatile bool imageReceivedFlag = false; // <-- NEW: Intercepts background network upload

// RFID Mode Timeout Variables
unsigned long rfidModeTimer = 0;
const unsigned long rfidTimeoutDuration = 30000; 

// --- NEW: HTML & JAVASCRIPT WEBPAGE INTERNAL STRING ---
// Uses HTML5 getUserMedia as described in your reference image to stream frames to the ESP32
const char HTML_PAGE[] PROGMEM = R"rawliteral(
<!DOCTYPE html>
<html>
<head>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ESP32 Camera Streamer</title>
    <style>
        body { font-family: Arial, sans-serif; text-align: center; background: #222; color: #fff; margin: 0; padding: 20px; }
        video, canvas { width: 100%; max-width: 400px; border-radius: 8px; background: #000; display: block; margin: 10px auto; }
        button { padding: 15px 30px; font-size: 18px; border: none; background: #00ff66; color: #000; font-weight: bold; border-radius: 5px; cursor: pointer; }
        #status { margin-top: 15px; color: #aaa; }
    </style>
</head>
<body>
    <h2>Phone to ESP32 Streamer</h2>
    <video id="video" autoplay playsinline></video>
    <button id="snap">CAPTURE & SEND IMAGE</button>
    <canvas id="canvas" style="display:none;"></canvas>
    <div id="status">Status: Initializing camera...</div>

    <script>
        const video = document.getElementById('video');
        const canvas = document.getElementById('canvas');
        const snap = document.getElementById('snap');
        const status = document.getElementById('status');

        // Access the mobile environment (rear) camera
        navigator.mediaDevices.getUserMedia({ video: { facingMode: "environment" }, audio: false })
            .then(stream => {
                video.srcObject = stream;
                status.innerText = "Status: Camera Ready. Frame streaming available.";
            })
            .catch(err => {
                status.innerText = "Status Error: " + err;
            });

        // Trigger capture and send image via HTTP POST string payload
        snap.addEventListener('click', () => {
            status.innerText = "Status: Capturing frame...";
            const context = canvas.getContext('2d');
            canvas.width = 320; // Lower resolution to ensure lightning-fast processing over Wi-Fi
            canvas.height = 240;
            context.drawImage(video, 0, 0, 320, 240);
            
            // Compress frame into baseline JPEG data URL format
            const dataUrl = canvas.toDataURL('image/jpeg', 0.6);
            
            status.innerText = "Status: Uploading payload to ESP32...";
            
            // HTTP POST endpoint transmission
            fetch('/upload', {
                method: 'POST',
                headers: { 'Content-Type': 'text/plain' },
                body: dataUrl
            })
            .then(response => response.text())
            .then(data => {
                status.innerText = "Status: Upload Successful! check ESP32 LCD Screen.";
            })
            .catch(error => {
                status.innerText = "Status Error: Transmission failed: " + error;
            });
        });
    </script>
</body>
</html>
)rawliteral";

// --- NEW: WEB SERVER HANDLERS ---
void handleRoot() {
  server.send_P(200, "text/html", HTML_PAGE);
}

// Decodes the base64 JPEG payload and forwards it as multipart/form-data
// to the real backend (upload.php). Returns true on a 200 response.
bool forwardImageToApi(const String& base64Data) {
  size_t decodedLen = 0;
  mbedtls_base64_decode(NULL, 0, &decodedLen,
                         (const unsigned char*)base64Data.c_str(), base64Data.length());
  if (decodedLen == 0) {
    Serial.println("Base64 decode failed: empty payload.");
    return false;
  }

  uint8_t* imageBuffer = (uint8_t*) malloc(decodedLen);
  if (!imageBuffer) {
    Serial.println("Out of memory allocating image buffer.");
    return false;
  }

  size_t actualLen = 0;
  int decodeResult = mbedtls_base64_decode(imageBuffer, decodedLen, &actualLen,
                                            (const unsigned char*)base64Data.c_str(), base64Data.length());
  if (decodeResult != 0) {
    Serial.printf("Base64 decode error: %d\n", decodeResult);
    free(imageBuffer);
    return false;
  }

  const String boundary = "ESP32CamBoundary7d81";
  String head = "--" + boundary + "\r\n";
  head += "Content-Disposition: form-data; name=\"image\"; filename=\"capture.jpg\"\r\n";
  head += "Content-Type: image/jpeg\r\n\r\n";
  String tail = "\r\n--" + boundary + "--\r\n";

  size_t totalLen = head.length() + actualLen + tail.length();
  uint8_t* requestBody = (uint8_t*) malloc(totalLen);
  if (!requestBody) {
    Serial.println("Out of memory allocating request body.");
    free(imageBuffer);
    return false;
  }
  memcpy(requestBody, head.c_str(), head.length());
  memcpy(requestBody + head.length(), imageBuffer, actualLen);
  memcpy(requestBody + head.length() + actualLen, tail.c_str(), tail.length());
  free(imageBuffer);

  WiFiClientSecure secureClient;
  secureClient.setInsecure(); // Prototype only: skips TLS cert validation. Pin a root CA for production.

  HTTPClient http;
  http.setTimeout(apiTimeoutMs);
  bool ok = false;

  if (http.begin(secureClient, apiUrl)) {
    http.addHeader("Content-Type", "multipart/form-data; boundary=" + boundary);
    int httpCode = http.POST(requestBody, totalLen);
    String response = http.getString();
    Serial.printf("API response [%d]: %s\n", httpCode, response.c_str());
    ok = (httpCode == 200);
    http.end();
  } else {
    Serial.println("Failed to connect to API.");
  }

  free(requestBody);
  return ok;
}

void handleUpload() {
  // If the system is actively waiting for an object scan input, intercept the POST request
  if (currentState != STATE_SCANNING) {
    server.send(400, "text/plain", "ESP32 is not in scanning mode.");
    return;
  }

  if (!server.hasArg("plain") || server.arg("plain").length() == 0) {
    server.send(400, "text/plain", "No image payload received.");
    return;
  }

  String body = server.arg("plain");

  // Strip the "data:image/jpeg;base64," prefix from the dataURL, if present
  int commaIndex = body.indexOf(',');
  String base64Data = (commaIndex >= 0) ? body.substring(commaIndex + 1) : body;

  if (forwardImageToApi(base64Data)) {
    imageReceivedFlag = true;
    server.send(200, "text/plain", "OK");
  } else {
    server.send(502, "text/plain", "Failed to forward image to API.");
  }
}

void setup() {
  Serial.begin(115200);
  delay(200);

  // Initialize Outputs
  pinMode(GREEN_LED_PIN, OUTPUT);
  pinMode(RED_LED_PIN, OUTPUT);
  pinMode(BUZZER_PIN, OUTPUT);
  
  // Power-up indicators check
  digitalWrite(GREEN_LED_PIN, HIGH);
  digitalWrite(RED_LED_PIN, HIGH);
  tone(BUZZER_PIN, 2000, 100);
  delay(100);
  digitalWrite(GREEN_LED_PIN, LOW);
  digitalWrite(RED_LED_PIN, LOW);

  // Initialize I2C Display
  Wire.begin(LCD_SDA, LCD_SCL);
  lcd.init();
  lcd.backlight();

  // --- NEW: START WI-FI CONNECTION ---
  lcd.clear();
  lcd.print("Connecting Wi-Fi");
  WiFi.begin(ssid, password);
  while (WiFi.status() != WL_CONNECTED) {
    delay(500);
    Serial.print(".");
  }
  Serial.println("\nWi-Fi Connected!");
  Serial.print("IP Address: ");
  Serial.println(WiFi.localIP());

  // Show IP address briefly on the boot layout screen
  lcd.clear();
  lcd.setCursor(0, 0);
  lcd.print("IP: ");
  lcd.print(WiFi.localIP());
  delay(3000);

  // --- NEW: CONFIGURE HTTP ROUTES ---
  server.on("/", handleRoot);
  server.on("/upload", HTTP_POST, handleUpload);
  server.begin();
  Serial.println("HTTP Web Server Started.");
  
  // Initialize Hardware SPI bus and RC522 Hardware
  SPI.begin();
  
  // Initialize Random Seed using analog noise
  randomSeed(analogRead(34));

  Serial.println("System Ready.");
  resetToMenu();
}

void loop() {
  // CRITICAL: Must be run continuously to parse background HTTP uploads
  server.handleClient();

  // Non-blocking WiFi watchdog: setup() only connects once, so without this
  // the ESP32 would silently stay offline (and unreachable) after any drop.
  if (millis() - lastWifiCheck > wifiCheckInterval) {
    lastWifiCheck = millis();
    if (WiFi.status() != WL_CONNECTED) {
      Serial.println("WiFi disconnected, reconnecting...");
      WiFi.disconnect();
      WiFi.begin(ssid, password);
    }
  }

  // Always read all control pins globally to process immediate actions
  int confirmVal  = touchRead(CONFIRM_TOUCH_PIN);
  int cancelVal   = touchRead(CANCEL_TOUCH_PIN);
  int camModeVal  = touchRead(CAMERA_MODE_PIN);
  int rfidModeVal = touchRead(RFID_MODE_PIN);
  int scanVal     = touchRead(SCAN_TOUCH_PIN);

  // ==========================================
  //   GLOBAL OVERRIDE: SMART HIERARCHICAL CANCEL
  // ==========================================
  if (currentState != MODE_MENU && cancelVal < cancelThreshold) {
    
    // CASE 1: User is looking at a wrong object option or waiting for transmission -> Step back to "Scan Object?"
    if (currentState == STATE_SCANNING || currentState == STATE_DISPLAY_OBJ) {
      Serial.println("Stepping back to Scan prompt.");
      tone(BUZZER_PIN, 600, 150); 
      
      currentState = MODE_CAMERA;
      objectDetected = false;
      imageReceivedFlag = false;
      
      lcd.clear();
      lcd.setCursor(0, 0);
      lcd.print("Scan Object?    ");
      lcd.setCursor(0, 1);
      lcd.print("Press Pin 15... ");
    }
    // CASE 2: User is on the "Scan Object?" prompt or RFID mode -> Step all the way back to main menu
    else {
      Serial.println("Stepping all the way back to Main Menu.");
      if (currentState == MODE_RFID) {
        mfrc522.PICC_HaltA();
        mfrc522.PCD_StopCrypto1();
      }
      tone(BUZZER_PIN, 600, 300); 
      resetToMenu();
    }
    
    delay(500); // Standard debounce window
    return;     
  }

  switch (currentState) {
    
    // ==========================================
    //   STATE: MAIN SELECTION MENU
    // ==========================================
    case MODE_MENU:
      if (camModeVal < cameraModeThreshold) {
        Serial.println("Selected Camera Mode");
        currentState = MODE_CAMERA;
        objectDetected = false;
        imageReceivedFlag = false;
        
        lcd.clear();
        lcd.setCursor(0, 0);
        lcd.print("Scan Object?    ");
        lcd.setCursor(0, 1);
        lcd.print("Press Pin 15... ");
      } 
      else if (rfidModeVal < rfidModeThreshold) {
        Serial.println("Selected RFID Mode");
        currentState = MODE_RFID;
        rfidModeTimer = millis(); 
        
        digitalWrite(RST_PIN, LOW);
        delay(50);
        digitalWrite(RST_PIN, HIGH);
        delay(50);
        
        mfrc522.PCD_Init();
        mfrc522.PCD_SetAntennaGain(mfrc522.RxGain_max); 
        
        lcd.clear();
        lcd.setCursor(0, 0);
        lcd.print("Scan Card       ");
      }
      break;

    // ==========================================
    //   STATE: CAMERA MODE (WAITING FOR SCAN BUTTON)
    // ==========================================
    case MODE_CAMERA:
      if (scanVal < scanThreshold) {
        Serial.println("Scan triggered manually. Awaiting phone frame transmission...");
        currentState = STATE_SCANNING;
        imageReceivedFlag = false;
        
        lcd.clear();
        lcd.setCursor(0, 0);
        lcd.print("Awaiting Frame.. ");
        lcd.setCursor(0, 1);
        lcd.print("Use your phone! ");
      }
      break;

    // ==========================================
    //   STATE: STATE_SCANNING (AWAITING SERVER IMAGE FROM PHONE)
    // ==========================================
    case STATE_SCANNING:
      // Wait for imageReceivedFlag from the server route
      if (imageReceivedFlag) {
        imageReceivedFlag = false;
        currentState = STATE_DISPLAY_OBJ;
        objectDetected = true;

        // Pick a random object option
        int randomIndex = random(0, 4);
        selectedObject = recognizedObjects[randomIndex];

        lcd.clear();
        lcd.setCursor(0, 0);
        lcd.print(selectedObject);
        lcd.setCursor(0, 1);
        lcd.print("Confirm/Cancel? ");
      }
      break;

    // ==========================================
    //   STATE: STATE_DISPLAY_OBJ (OBJECT REVIEW DECISION STAGE)
    // ==========================================
    case STATE_DISPLAY_OBJ:
      if (objectDetected) {
        if (confirmVal < confirmThreshold) {
          executeConfirmAction();
        }
      }
      break;

    // ==========================================
    //   STATE: RFID ATTENDANCE SCANNING MODE
    // ==========================================
    case MODE_RFID:
      int secondsLeft = (rfidTimeoutDuration - (millis() - rfidModeTimer)) / 1000;
      lcd.setCursor(0, 1);
      lcd.print("Time Left: ");
      lcd.print(secondsLeft);
      lcd.print("s   ");

      if (millis() - rfidModeTimer >= rfidTimeoutDuration) {
        Serial.println("RFID Mode Timeout!");
        lcd.clear();
        lcd.setCursor(0, 0);
        lcd.print("No Card Detected");
        delay(1500);
        resetToMenu();
        break;
      }

      if (mfrc522.PICC_IsNewCardPresent()) {
        if (mfrc522.PICC_ReadCardSerial()) {
          Serial.println("RFID Attendance Logged!");

          lcd.clear();
          lcd.setCursor(0, 0);
          lcd.print("Attendance      ");
          lcd.setCursor(0, 1);
          lcd.print("Logged          ");

          tone(BUZZER_PIN, 2000);
          digitalWrite(GREEN_LED_PIN, HIGH);
          delay(100);
          noTone(BUZZER_PIN);
          digitalWrite(GREEN_LED_PIN, LOW);

          delay(1900);

          mfrc522.PICC_HaltA();
          mfrc522.PCD_StopCrypto1();
          resetToMenu();
        }
      }
      break;
  }

  delay(10); // Decreased delay slightly to keep network polling fluid
}

// ==========================================
//   HELPER TRANSITION FUNCTIONS
// ==========================================

void executeConfirmAction() {
  digitalWrite(GREEN_LED_PIN, HIGH);
  lcd.clear();
  lcd.setCursor(0, 0);
  lcd.print("TOUCH DETECTED!");
  lcd.setCursor(0, 1);
  lcd.print("Log confirmed   ");

  tone(BUZZER_PIN, 2500);
  delay(100);
  noTone(BUZZER_PIN);
  delay(100);
  tone(BUZZER_PIN, 2500);
  delay(200);
  noTone(BUZZER_PIN);

  delay(1700);
  resetToMenu();
}

void executeCancelAction() {
  digitalWrite(RED_LED_PIN, HIGH);
  lcd.clear();
  lcd.setCursor(0, 0);
  lcd.print("TOUCH DETECTED!");
  lcd.setCursor(0, 1);
  lcd.print("Canceled log    ");

  tone(BUZZER_PIN, 600);
  delay(500);
  noTone(BUZZER_PIN);

  delay(1500);
  resetToMenu();
}

void resetToMenu() {
  digitalWrite(GREEN_LED_PIN, LOW);
  digitalWrite(RED_LED_PIN, LOW);
  noTone(BUZZER_PIN);

  currentState = MODE_MENU;

  lcd.clear();
  lcd.setCursor(0, 0);
  lcd.print("Scan or Read?   ");
  lcd.setCursor(0, 1);
  lcd.print("12=Cam | 13=RFID");
}


