<?php

declare(strict_types=1);

/**
 * Vision-LLM pipeline: one Hugging Face free-tier token/model does both
 * classification (object vs receipt vs unknown) and, for receipts, direct
 * structured extraction — no separate OCR step. A vision-LLM reading text
 * straight off the image is typically more accurate than OCR text piped
 * into a second LLM, since OCR mistakes can't propagate uncorrected.
 */

class AiPipelineException extends RuntimeException {}

// A subtype for failures worth retrying once (network blip, 5xx, empty
// response) — callers that only catch AiPipelineException still catch this
// via inheritance if a retry ever exhausts itself and rethrows.
class AiPipelineTransientException extends AiPipelineException {}

/**
 * The configured model is a reasoning model: it writes its actual analysis
 * (including e.g. the correct category ID) into a separate
 * `reasoning_content` field and only echoes a final answer into `content`
 * afterwards — so a low max_tokens truncates the response before `content`
 * is ever reached, even though the real answer already landed in
 * `reasoning_content`. Callers get both fields and decide how to use them.
 *
 * @return array{content:string, reasoning:string, combined:string}
 */
function hfChatCompletion(array $messages, int $maxTokens): array
{
    // One retry on a transient failure (network error or 5xx from the
    // provider) — free-tier inference infrastructure occasionally hiccups,
    // and a retry turns a one-off failure into a non-event for the user
    // instead of surfacing as an error on the device. 3 attempts total with
    // a short backoff between them.
    $lastError = null;
    for ($attempt = 1; $attempt <= 3; $attempt++) {
        try {
            return hfChatCompletionAttempt($messages, $maxTokens);
        } catch (AiPipelineTransientException $error) {
            $lastError = $error;
            if ($attempt < 3) {
                error_log("hfChatCompletion attempt $attempt failed, retrying: " . $error->getMessage());
                sleep($attempt);
            }
        }
    }
    throw new AiPipelineException($lastError->getMessage());
}

function hfChatCompletionAttempt(array $messages, int $maxTokens): array
{
    $configuration = config();
    if (trim($configuration['hf_token']) === '') throw new AiPipelineException('Server is missing HF_TOKEN');
    if (!function_exists('curl_init')) throw new AiPipelineException('Server requires curl');

    $payload = json_encode([
        'model' => $configuration['hf_model'],
        'messages' => $messages,
        'temperature' => 0,
        'max_tokens' => $maxTokens,
    ], JSON_UNESCAPED_SLASHES);
    if ($payload === false) throw new AiPipelineException('Could not prepare AI request');

    $curl = curl_init('https://router.huggingface.co/v1/chat/completions');
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . trim($configuration['hf_token']),
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: HomeLogger-AI/1.0',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 90,
    ]);
    $response = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $curlError = curl_error($curl);
    curl_close($curl);

    if ($response === false) throw new AiPipelineTransientException('AI service unavailable: ' . $curlError);
    $decoded = json_decode($response, true);
    if ($status < 200 || $status >= 300) {
        $provider = $decoded['error'] ?? null;
        $message = is_string($provider) ? $provider : (is_array($provider) ? ($provider['message'] ?? 'AI service error') : 'AI service returned HTTP ' . $status);
        // 5xx from the provider is almost certainly transient (overload, brief
        // outage); 4xx (bad request, auth, rate-limit-forever) won't be fixed
        // by retrying immediately.
        if ($status >= 500) throw new AiPipelineTransientException($message);
        throw new AiPipelineException($message);
    }
    $message = $decoded['choices'][0]['message'] ?? null;
    if (!is_array($message)) throw new AiPipelineTransientException('AI service returned an empty response');
    $content = trim((string) ($message['content'] ?? ''));
    $reasoning = trim((string) ($message['reasoning_content'] ?? $message['reasoning'] ?? ''));
    if ($content === '' && $reasoning === '') throw new AiPipelineTransientException('AI service returned an empty response');
    return ['content' => $content, 'reasoning' => $reasoning, 'combined' => trim($reasoning . "\n" . $content)];
}

/**
 * Classifies one capture against every known object category, using
 * verified training examples as few-shot context (same technique as the
 * reference project's classifier — reference examples visibly improve
 * accuracy on visually similar daily items like milk bottles).
 *
 * @return array{type:'object', category:array, confidence:'high'|'medium'|'low'}|array{type:'receipt'}|array{type:'unknown'}
 */
function classifyCapture(string $imagePath, string $mime, array $objectCategories, array $trainingExamples): array
{
    if (count($objectCategories) < 1) throw new AiPipelineException('Create at least one object category first');

    $image = file_get_contents($imagePath);
    if ($image === false) throw new AiPipelineException('Could not read the uploaded image');

    $content = [];
    foreach ($trainingExamples as $example) {
        $dataUrl = imageDataUrl($example['image_path']);
        if (!$dataUrl) continue;
        $content[] = ['type' => 'text', 'text' => "Reference example: category ID {$example['category_public_id']} ({$example['category_name']})."];
        $content[] = ['type' => 'image_url', 'image_url' => ['url' => $dataUrl]];
    }

    $allowed = implode('; ', array_map(static fn(array $c): string => "{$c['public_id']}={$c['name']}", $objectCategories));
    $content[] = [
        'type' => 'text',
        'text' => "Classify the next target image. It is one of three things: (1) a known household object matching one of these category IDs: $allowed; (2) a paper receipt, invoice, or bill with printed text and prices; (3) something that matches neither. "
            . "You may reason briefly, but you MUST end your response with exactly these two final lines, in this order, nothing after them: "
            . "CONFIDENCE: <level>\nANSWER: <token> — where <token> is a category ID from the list, or the word RECEIPT, or the word UNKNOWN, and <level> is HIGH, MEDIUM, or LOW reflecting how sure you are <token> is correct (LOW if the photo is blurry, cropped, poorly lit, shows more than one plausible item, or only loosely resembles a reference example rather than clearly matching one). Reference examples above (if any) are verified by users and should guide your object matches.",
    ];
    $content[] = ['type' => 'image_url', 'image_url' => ['url' => 'data:' . $mime . ';base64,' . base64_encode($image)]];

    // Generous budget: this is a reasoning model, and few-shot reference
    // examples measurably lengthen its reasoning — a tight max_tokens was
    // observed truncating mid-evaluation, right after it happened to write
    // out a category ID it was about to reject, producing a false match.
    // 700 was once enough but wasn't anymore once this model started double-
    // checking itself at length even with just one reference example — the
    // extraction below is now robust to truncation regardless (only reads
    // the single line right after the ANSWER: marker), but a bigger budget
    // still means the model less often needs to be rescued by that at all.
    $result = hfChatCompletion([['role' => 'user', 'content' => $content]], 1200);

    // Prefer whatever follows the LAST "ANSWER:" marker — the model's
    // reasoning legitimately mentions every candidate ID while evaluating
    // and rejecting it, so scanning the whole text for any mention is unsafe
    // (that's exactly how the false-match above happened). Only the answer
    // line should be trusted; fall back to a whole-text scan solely if the
    // model didn't comply or got cut off before writing the marker.
    $combined = $result['combined'];
    $markerPos = strripos($combined, 'ANSWER:');
    if ($markerPos !== false) {
        // Only the token immediately after the marker, up to the next line
        // break — NOT everything to the end of the string. This model
        // sometimes writes a second, truncated restatement of its reasoning
        // in `content` even after already answering cleanly, and that
        // trailing text can incidentally mention a category name while
        // explicitly rejecting it (e.g. "...does not match Milk bottle or
        // ...") — scanning past the answer line let that false-match win
        // over the correct answer sitting right there.
        $afterMarker = substr($combined, $markerPos + strlen('ANSWER:'));
        $lineEnd = strpos($afterMarker, "\n");
        $answerText = $lineEnd !== false ? substr($afterMarker, 0, $lineEnd) : substr($afterMarker, 0, 60);
    } else {
        $answerText = $combined;
    }
    $normalized = trim($answerText, " \t\n\r\0\x0B.,\"'`");

    // Same last-marker-wins approach as ANSWER: above, and defaults to
    // 'medium' (not 'high') whenever the model omits or garbles this line —
    // an unparseable confidence signal shouldn't be trusted as if it were a
    // confident one. Only an explicit HIGH skips the review queue.
    $confidence = 'medium';
    $confidenceMarkerPos = strripos($combined, 'CONFIDENCE:');
    if ($confidenceMarkerPos !== false) {
        $afterConfidence = substr($combined, $confidenceMarkerPos + strlen('CONFIDENCE:'));
        $confidenceToken = strtolower(trim(substr($afterConfidence, 0, 20), " \t\n\r\0\x0B.,\"'`"));
        $confidenceToken = strtok($confidenceToken, " \t\n\r");
        if (in_array($confidenceToken, ['high', 'medium', 'low'], true)) $confidence = $confidenceToken;
    }

    foreach ($objectCategories as $category) {
        if (str_contains($normalized, $category['public_id'])) return ['type' => 'object', 'category' => $category, 'confidence' => $confidence];
    }
    // Longest name first, so "Milk bottle" is checked before a shorter name
    // that might otherwise false-match as a substring of it.
    $sorted = $objectCategories;
    usort($sorted, static fn(array $a, array $b): int => strlen($b['name']) <=> strlen($a['name']));
    foreach ($sorted as $category) {
        if (stripos($normalized, $category['name']) !== false) return ['type' => 'object', 'category' => $category, 'confidence' => $confidence];
    }

    if (stripos($normalized, 'RECEIPT') !== false) return ['type' => 'receipt'];
    if ($markerPos === false) {
        // No answer marker anywhere — genuinely inconclusive, not just a
        // parsing edge case. Log it; treating as UNKNOWN is the safe default
        // (a dashboard notification, not a silently wrong object log).
        error_log('classifyCapture: no ANSWER marker in model response: ' . $combined);
    }
    return ['type' => 'unknown'];
}

/**
 * Reads a receipt image directly into structured JSON in one vision-LLM
 * pass. Response is defensively parsed since models occasionally wrap JSON
 * in markdown fences despite instructions not to.
 *
 * The returned discount/tax are guaranteed consistent with subtotal and
 * total (subtotal - discount + tax == total, always) — if the model's own
 * numbers don't already add up, discount/tax are overridden with a single
 * net adjustment derived from the gap between subtotal and the printed
 * total (the most reliable single figure on a receipt), rather than storing
 * a mismatch for someone to notice later.
 *
 * @return array{merchant:?string, items:array<int,array{name:string,quantity:float,unit_price:?float,line_total:?float}>, subtotal:?float, discount:?float, tax:?float, total:float}
 */
function extractReceipt(string $imagePath, string $mime): array
{
    $image = file_get_contents($imagePath);
    if ($image === false) throw new AiPipelineException('Could not read the uploaded image');

    $prompt = 'Read this receipt image and return ONLY a single JSON object, no markdown fencing, no explanation, in exactly this shape: '
        . '{"merchant": string or null, "items": [{"name": string, "quantity": number, "unit_price": number or null, "line_total": number or null}], "subtotal": number or null, "discount": number or null, "tax": number or null, "total": number}. '
        . 'Before answering, deliberately scan the area between the item list and the final total for any extra line there — receipts very often have one. '
        . '"subtotal" is the sum of line items before any adjustment (often labeled "Amount" or "Gross"). '
        . '"discount" is any amount subtracted — a line labeled "Less", "Discount", "Off", "Rebate", or similar — as a positive number representing how much was taken off. Look carefully: this line is easy to miss, is not always labeled exactly "Discount", and its absence should only be reported (null) after you have specifically checked for it, not by default. '
        . '"tax" is any amount added — VAT, tax, service charge, or similar — as a positive number (null if there is genuinely no such line after checking). '
        . '"total" is the final amount actually payable/charged (often labeled "Net Taka", "Grand Total", "Amount Payable", or similar) — this must reflect subtotal minus discount plus tax, not just the raw item sum. If your computed total does not equal subtotal minus discount plus tax, re-examine the image — you likely misread one of the values or missed a discount/tax line. '
        . 'Use null only for a field you have specifically checked for and still cannot find. Numbers must be plain numbers without currency symbols or thousands separators.';

    $content = [
        ['type' => 'text', 'text' => $prompt],
        ['type' => 'image_url', 'image_url' => ['url' => 'data:' . $mime . ';base64,' . base64_encode($image)]],
    ];

    // Generous budget: reasoning tokens (this model's hidden analysis pass)
    // come out of the same max_tokens before the JSON in `content` is ever
    // reached, and a real receipt's item list can run long.
    $result = hfChatCompletion([['role' => 'user', 'content' => $content]], 3000);
    // Prefer the clean `content` field; some responses put the JSON in
    // `reasoning_content` instead (or truncate content before finishing), so
    // fall back to the combined text if content alone doesn't parse.
    $decoded = null;
    foreach (array_unique([$result['content'], $result['combined']]) as $candidate) {
        if ($candidate === '') continue;
        try {
            $decoded = json_decode(extractJsonObject($candidate), true);
        } catch (AiPipelineException) {
            continue;
        }
        if (is_array($decoded)) break;
    }
    if (!is_array($decoded)) throw new AiPipelineException('Could not parse the receipt data returned by the AI service');

    $items = [];
    foreach ((array) ($decoded['items'] ?? []) as $item) {
        if (!is_array($item)) continue;
        $name = trim((string) ($item['name'] ?? ''));
        if ($name === '') continue;
        $items[] = [
            'name' => mb_substr($name, 0, 150),
            'quantity' => max(0.01, round((float) ($item['quantity'] ?? 1), 2)),
            'unit_price' => isset($item['unit_price']) && is_numeric($item['unit_price']) ? round((float) $item['unit_price'], 2) : null,
            'line_total' => isset($item['line_total']) && is_numeric($item['line_total']) ? round((float) $item['line_total'], 2) : null,
        ];
    }
    if (count($items) < 1) throw new AiPipelineException('The AI service could not read any line items on this receipt');

    $subtotal = isset($decoded['subtotal']) && is_numeric($decoded['subtotal']) ? round((float) $decoded['subtotal'], 2) : null;
    $discount = isset($decoded['discount']) && is_numeric($decoded['discount']) ? round((float) $decoded['discount'], 2) : null;
    $tax = isset($decoded['tax']) && is_numeric($decoded['tax']) ? round((float) $decoded['tax'], 2) : null;
    $itemSum = round(array_sum(array_column($items, 'line_total')), 2);
    $modelTotal = isset($decoded['total']) && is_numeric($decoded['total']) ? round((float) $decoded['total'], 2) : null;
    $effectiveSubtotal = $subtotal ?? $itemSum;

    if ($modelTotal !== null) {
        // The printed total is the most reliable single figure on a receipt
        // (largest, most prominent, least likely to be misread) — trust it
        // as the anchor, and derive discount/tax from its gap against
        // subtotal so the ledger is always internally consistent
        // (subtotal - discount + tax == total, exactly), rather than storing
        // a separately-guessed discount/tax that might not agree with it.
        // A negative gap (total below subtotal) means a net discount; a
        // positive gap (total above subtotal) means net tax/other addition.
        $gap = round($modelTotal - $effectiveSubtotal, 2);
        if (abs($gap) > 0.01) {
            error_log("extractReceipt: total ($modelTotal) didn't match subtotal ($effectiveSubtotal) minus discount (" . ($discount ?? 'null') . ') plus tax (' . ($tax ?? 'null') . ") — auto-adjusting to reconcile, gap=$gap");
            if ($gap < 0) { $discount = round(-$gap, 2); $tax = null; }
            else { $tax = round($gap, 2); $discount = null; }
        }
        $total = $modelTotal;
    } else {
        // No total was read at all — nothing to reconcile against, derive it
        // from subtotal/items and whatever discount/tax were found.
        $total = max(0, round($effectiveSubtotal - ($discount ?? 0) + ($tax ?? 0), 2));
    }

    return [
        'merchant' => isset($decoded['merchant']) && is_string($decoded['merchant']) && trim($decoded['merchant']) !== '' ? mb_substr(trim($decoded['merchant']), 0, 120) : null,
        'items' => $items,
        'subtotal' => $effectiveSubtotal,
        'discount' => $discount,
        'tax' => $tax,
        'total' => $total,
    ];
}

function extractJsonObject(string $text): string
{
    $trimmed = trim($text);
    if (str_starts_with($trimmed, '```')) {
        $trimmed = preg_replace('/^```[a-zA-Z]*\s*/', '', $trimmed);
        $trimmed = preg_replace('/```\s*$/', '', (string) $trimmed);
        $trimmed = trim((string) $trimmed);
    }
    $start = strpos($trimmed, '{');
    $end = strrpos($trimmed, '}');
    if ($start === false || $end === false || $end < $start) throw new AiPipelineException('AI service did not return JSON');
    return substr($trimmed, $start, $end - $start + 1);
}
