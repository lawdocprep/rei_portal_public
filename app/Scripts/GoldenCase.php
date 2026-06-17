<?php
declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

/*
|--------------------------------------------------------------------------
| REI Portal Golden Case Builder
|--------------------------------------------------------------------------
| Creates a deterministic eviction case through prepare-for-filing.
*/

use App\Services\Events\CaseEventService;


error_reporting(E_ALL);
ini_set('display_errors', '1');

$BASE  = "http://localhost:8000";

$TOKEN = "eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiJ9.eyJpc3MiOiJyZWktcG9ydGFsIiwic3ViIjoidXNlcjoxIiwidGVuYW50X2lkIjoiODAwMjU5N2UtZWM4MS00MWE4LTg3NWYtZmUzYWU4MTUwOGY3IiwiYWN0b3JfaWQiOiIxMTMwMjM0YS0xNTk3LTQ0NWItYTgwNi0zMTI4YzBmZTcwNDQiLCJhY3Rvcl90eXBlIjoicGVyc29uIiwic2VydmljZV9jb250ZXh0IjoiaHVtYW4iLCJzY29wZXMiOlsic2Vzc2lvbjpyZWFkIiwiY2FzZXM6cmVhZCIsImNhc2VzOndyaXRlIiwiZG9jdW1lbnQ6cmVhZCIsImRvY3VtZW50OndyaXRlIiwiZG9jdW1lbnQ6ZmluYWxpemUiLCJkb2N1bWVudDpleHBvcnQiLCJldmlkZW5jZTphdWRpdDpyZWFkIiwicHJvcGVydGllczpyZWFkIiwicHJvcGVydGllczp3cml0ZSIsInJlZmVyZW5jZTp3cml0ZSIsInRpbWVsaW5lOnBvbGljeTpyZWFkIiwidGltZWxpbmU6cG9saWN5OndyaXRlIiwidGltZWxpbmU6cmVhZCIsImNhc2U6aW5ncmVzczpzdWJtaXQiXSwicm9sZXMiOlsic3lzdGVtX2FkbWluIl0sImlhdCI6MTc3OTg5NTYyOSwiZXhwIjoxNzgwNTAwNDI5LCJqdGkiOiJjNDczYjgwMjZlZTc0MzZiYzgxNTQ3YjQ1Zjc2NzIzYSJ9.jV_Xq2cEcHYw2RAlt_jtgtJ-h0nPOwNE7zBroWvBHKJ2A_EkbPQJLXFcykCm0cVb9Mw4SsNZl6ArLxuoFKRqiIHD2iNVm1Wrexlte6rSm7hCPRZNJXqdFb003RmmV4LzZN9RXwfVAAsD9EQQDaBg9RLoTRNDwat6ZonohDyfMfL7AUehchH4wsgK0HE9V9tOifQmyT0op5ETFOwirlTINhhcyHvS2RIxyhARGQstdULHWAaCwcZGTnHrc5ZzHV2bp-A-mkurgec-ocwVXXNfMg6iq19U-nPMFPrdgdwZ6K5_I3Qo9LZdhCITUuaNQdP-IWhXe87KfPB_ceOPHJEjMg";

$TENANT_ID = "8002597e-ec81-41a8-875f-fe3ae81508f7";
$ACTOR_ID  = "1130234a-1597-445b-a806-3128c0fe7044";

/*
|--------------------------------------------------------------------------
| Database Connection
|--------------------------------------------------------------------------
*/

$pdo = new PDO(
    "pgsql:host=localhost;port=5432;dbname=portal",
    "portal",
    "portal_pw",
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

/*
|--------------------------------------------------------------------------
| HTTP Client
|--------------------------------------------------------------------------
*/

function api(string $method, string $uri, ?array $body = null): array
{
    global $BASE, $TOKEN;

    $ch = curl_init();

    $headers = [
        "Authorization: Bearer $TOKEN",
        "Content-Type: application/json",
        "Accept: application/json",
    ];

    curl_setopt_array($ch, [
        CURLOPT_URL            => $BASE . $uri,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_HEADER         => false,
    ]);

    if ($body !== null) {
        $json = json_encode($body, JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new Exception("Failed to JSON encode request body for $method $uri");
        }
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
    }

    $resp = curl_exec($ch);

    if ($resp === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new Exception("Curl error for $method $uri: $err");
    }

    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $json = json_decode($resp, true);

    if ($code >= 400) {
        throw new Exception("HTTP $code → $resp");
    }

    if (!is_array($json)) {
        throw new Exception("Non-JSON response for $method $uri: $resp");
    }

    return $json;
}

function field(array $r, array $path): mixed
{
    $cur = $r;

    foreach ($path as $segment) {
        if (!is_array($cur) || !array_key_exists($segment, $cur)) {
            return null;
        }
        $cur = $cur[$segment];
    }

    return $cur;
}

function id(?array $r): string
{
    if ($r === null) {
        throw new Exception("Unable to extract ID");
    }

    foreach ([
        ['id'],
        ['request_id'],
        ['document_id'],
        ['case_id'],
        ['legal_action_id'],
        ['case', 'id'],
        ['legal_action', 'id'],
        ['property', 'id'],
        ['party', 'id'],
    ] as $path) {
        $v = field($r, $path);
        if (is_string($v) && $v !== '') {
            return $v;
        }
    }

    print_r($r);
    throw new Exception("Unable to extract ID");
}

function requireStringField(array $r, array $path, string $label): string
{
    $v = field($r, $path);

    if (!is_string($v) || $v === '') {
        print_r($r);
        throw new Exception("Missing $label");
    }

    return $v;
}

function logStep(string $msg): void
{
    echo "\n=== $msg ===\n";
}

function printEventTimeline(PDO $pdo, string $tenantId, string $caseId): void
{
    $stmt = $pdo->prepare("
        SELECT
            case_sequence,
            event_type,
            causation_id,
            correlation_id
        FROM case_events
        WHERE tenant_id = :tenant
          AND case_id = :case
        ORDER BY case_sequence
    ");

    $stmt->execute([
        ':tenant' => $tenantId,
        ':case'   => $caseId
    ]);

    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "\n--- EVENT TIMELINE ---\n";

    foreach ($events as $e) {
        $seq = str_pad((string)$e['case_sequence'], 3, ' ', STR_PAD_LEFT);
        echo "{$seq}  {$e['event_type']}";

        if (!empty($e['causation_id'])) {
            echo "  ← caused by {$e['causation_id']}";
        }

        echo "\n";
    }

    echo "----------------------\n\n";
}

function ensureAction(string $caseId, string $action): void
    {
        $state = api('GET', "/cases/$caseId/procedural-state");

        $current = $state['current_phase_key'] ?? '';

        if ($current === $action) {
            echo "=== {$action} (already satisfied) ===\n";
            return;
        }

        api('POST',
            "/cases/$caseId/actions",
            [
                'requested_action' => $action
            ]
        );
    }

/*
|--------------------------------------------------------------------------
| 1. Create Case
|--------------------------------------------------------------------------
*/

logStep("Create Case");

$case = api("POST", "/cases", [
    "status"    => "open",
    "opened_at" => gmdate("c")
]);

$CASE_ID = id($case);

echo "CASE_ID = $CASE_ID\n";
printEventTimeline($pdo, $TENANT_ID, $CASE_ID);

/*
|--------------------------------------------------------------------------
| 2. Create Property
|--------------------------------------------------------------------------
*/

logStep("Create Property");

$prop = api("POST", "/properties", [
    "address_line1"      => "123 Golden Way",
    "city"               => "Phoenix",
    "state"              => "AZ",
    "zip"                => "85007",
    "county"             => 'MARICOPA',
    "legal_description"  => "Lot 1, Golden Subdivision"
]);

$PROPERTY_ID = id($prop);

echo "PROPERTY_ID = $PROPERTY_ID\n";
printEventTimeline($pdo, $TENANT_ID, $CASE_ID);

/*
|--------------------------------------------------------------------------
| 3. Link Property To Case
|--------------------------------------------------------------------------
*/

logStep("Link Property To Case");

api("POST", "/properties/$PROPERTY_ID/cases", [
    "property_id" => $PROPERTY_ID,
    "case_id"     => $CASE_ID
]);

printEventTimeline($pdo, $TENANT_ID, $CASE_ID);

/*
|--------------------------------------------------------------------------
| 4. Create Owner Party
|--------------------------------------------------------------------------
*/

logStep("Create Owner Party");

$owner = api("POST", "/cases/$CASE_ID/parties", [
    "party_role" => "owner",
    "actor_id" => $ACTOR_ID,
    "actor_type" => "organization",
    "first_name" => "Golden",
    "last_name"  => "Owner LLC",
    "address_line1" => "123 Golden Way",
    "city" => "Phoenix",
    "state" => "AZ",
    "zip" => "85007"
]);

$OWNER_ACTOR_ID = $owner['actor_id'] ?? null;

if (!$OWNER_ACTOR_ID) {
    print_r($owner);
    throw new Exception("Owner actor_id missing from response");
}

echo "OWNER_ACTOR_ID = $OWNER_ACTOR_ID\n";
printEventTimeline($pdo, $TENANT_ID, $CASE_ID);

/*
|--------------------------------------------------------------------------
| 4.5 Resolve Purchaser Entity
|--------------------------------------------------------------------------
*/

logStep("Resolve Purchaser Entity");

$stmt = $pdo->prepare("
    SELECT id
    FROM purchaser_entities
    WHERE tenant_id = :tenant_id
      AND owner_actor_id = :owner_actor_id
      AND lower(legal_name) = lower(:legal_name)
    LIMIT 1
");

$stmt->execute([
    ':tenant_id'      => $TENANT_ID,
    ':owner_actor_id' => $ACTOR_ID,
    ':legal_name'     => 'Golden Owner LLC'
]);

$PURCHASER_ENTITY_ID = $stmt->fetchColumn();

if (!$PURCHASER_ENTITY_ID) {
    logStep("Create Purchaser Entity");

    $stmt = $pdo->prepare("
        INSERT INTO purchaser_entities (
            id,
            tenant_id,
            owner_actor_id,
            legal_name,
            entity_type,
            created_at,
            updated_at
        )
        VALUES (
            uuid_generate_v4(),
            :tenant_id,
            :owner_actor_id,
            :legal_name,
            'llc',
            now(),
            now()
        )
        RETURNING id
    ");

    $stmt->execute([
        ':tenant_id'      => $TENANT_ID,
        ':owner_actor_id' => $ACTOR_ID,
        ':legal_name'     => 'Golden Owner LLC'
    ]);

    $PURCHASER_ENTITY_ID = $stmt->fetchColumn();
}

if (!is_string($PURCHASER_ENTITY_ID) || $PURCHASER_ENTITY_ID === '') {
    throw new Exception("Failed to resolve purchaser entity");
}

echo "PURCHASER_ENTITY_ID = $PURCHASER_ENTITY_ID\n";
printEventTimeline($pdo, $TENANT_ID, $CASE_ID);

/*
|--------------------------------------------------------------------------
| 4.6 Create Property Ownership Relationship
|--------------------------------------------------------------------------
*/

logStep("Create Property Ownership Relationship");

api("POST", "/properties/$PROPERTY_ID/relationships", [
    "actor_id"            => $ACTOR_ID,
    "role"                => "owner",
    "percentage_interest" => 100
]);

echo "Ownership Relationship Created\n";
printEventTimeline($pdo, $TENANT_ID, $CASE_ID);

/*
|--------------------------------------------------------------------------
| 5. Create Occupant Party
|--------------------------------------------------------------------------
*/

logStep("Create Occupant Party");

$occ = api("POST", "/cases/$CASE_ID/parties", [

    "party_role" => "occupant",
    "actor_type" => "person",

    "first_name" => "John",
    "last_name" => "Doe",

    "address_line1" => "123 Golden Way",
    "city" => "Phoenix",
    "state" => "AZ",
    "zip" => "85007"
]);

$OCCUPANT_ACTOR_ID = $occ['actor_id'] ?? null;

if (!$OCCUPANT_ACTOR_ID) {
    print_r($occ);
    throw new Exception("Occupant actor_id missing from response");
}

echo "OCCUPANT_ACTOR_ID = $OCCUPANT_ACTOR_ID\n";
printEventTimeline($pdo, $TENANT_ID, $CASE_ID);

/*
|--------------------------------------------------------------------------
| 6. Insert Foreclosure Sale Event
|--------------------------------------------------------------------------
*/

logStep("Insert Foreclosure Sale Event");

$eventService = new CaseEventService($pdo);

$eventId = $eventService->emitEvent(
    'foreclosure_sale',
    [
        'sale_date'            => date('Y-m-d'),
        'purchaser_entity_id'  => $PURCHASER_ENTITY_ID
    ],
    $TENANT_ID,
    $CASE_ID,
    $ACTOR_ID
);

if (!is_string($eventId) || $eventId === '') {
    throw new Exception("Failed to emit foreclosure_sale event");
}

echo "Foreclosure sale recorded\n";
echo "EVENT_ID = $eventId\n";
printEventTimeline($pdo, $TENANT_ID, $CASE_ID);

/*
|--------------------------------------------------------------------------
| 7. Create Demand Action
|--------------------------------------------------------------------------
*/

logStep("Create Demand Action");

$req = api(
    "POST",
    "/cases/$CASE_ID/action-requests",
    [
        "requested_action" => "az_demand_for_possession"
    ]
);

$ACTION_REQUEST_ID = id($req);

echo "ACTION_REQUEST_ID = $ACTION_REQUEST_ID\n";

printEventTimeline($pdo, $TENANT_ID, $CASE_ID);
/*
|--------------------------------------------------------------------------
| 8. Execute Demand
|--------------------------------------------------------------------------
*/

logStep("Execute Demand");

api("POST", "/action-requests/$ACTION_REQUEST_ID/execute");

printEventTimeline($pdo, $TENANT_ID, $CASE_ID);


/*
|--------------------------------------------------------------------------
| 9. Upload Demand Exhibit
|--------------------------------------------------------------------------
*/

logStep("Upload Demand Exhibit");

$doc = api("POST", "/cases/$CASE_ID/documents", [
    "document_type_key" => "demand_for_possession",
    "is_evidence"       => true
]);

$CASE_DOCUMENT_ID    = requireStringField($doc, ['case_document_id'], 'case_document_id');
$BACKING_DOCUMENT_ID = requireStringField($doc, ['document_id'], 'document_id');

echo "CASE_DOCUMENT_ID = $CASE_DOCUMENT_ID\n";
echo "BACKING_DOCUMENT_ID = $BACKING_DOCUMENT_ID\n";

$hash = hash_file("sha256", "C:\\rei_portal\\demand_test.pdf");
if (!is_string($hash) || $hash === '') {
    throw new Exception("Failed to hash demand_test.pdf");
}

api("POST", "/case-documents/$CASE_DOCUMENT_ID/versions", [
    "storage_uri"  => "local://demand_test.pdf",
    "content_hash" => $hash,
    "source"       => "upload",
    "make_current" => true
]);

api("POST", "/case-documents/$CASE_DOCUMENT_ID/finalize");

echo "Demand for Possession Uploaded\n";
printEventTimeline($pdo, $TENANT_ID, $CASE_ID);

/*
|--------------------------------------------------------------------------
| Provide Proof of Delivery of Demand
|--------------------------------------------------------------------------
*/

logStep("Record Demand Delivery");

/*
|--------------------------------------------------------------------------
| 1. Create Affidavit of Service Document
|--------------------------------------------------------------------------
*/

$doc = api(
    "POST",
    "/cases/$CASE_ID/documents",
    [
        "document_type_key" => "az_certified_mail_return_receipt",
        "title" => "Proof of Demand Delivery",
        "is_evidence" => true
    ]
);

$AFFIDAVIT_CASE_DOCUMENT_ID = $doc["case_document_id"];
$AFFIDAVIT_DOCUMENT_ID      = $doc["document_id"];

echo "AFFIDAVIT_CASE_DOCUMENT_ID = $AFFIDAVIT_CASE_DOCUMENT_ID\n";


/*
|--------------------------------------------------------------------------
| 2. Upload Proof PDF
|--------------------------------------------------------------------------
*/

$proofPath = "C:\\rei_portal\\proof_of_demand_delivery.pdf";

if (!file_exists($proofPath)) {
    throw new Exception("Proof PDF not found: $proofPath");
}

$hash = "sha256:" . hash_file("sha256", $proofPath);

$uri = "file:///" . str_replace("\\", "/", $proofPath);

api(
    "POST",
    "/case-documents/$AFFIDAVIT_CASE_DOCUMENT_ID/versions",
    [
        "storage_uri"  => $uri,
        "content_hash" => $hash,
        "source"       => "manual_upload",
        "page_count"   => 1
    ]
);

echo "Affidavit PDF uploaded\n";


/*
|--------------------------------------------------------------------------
| 3. Locate Certified Mail Service Task
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT id
    FROM demand_delivery_events
    WHERE tenant_id = :tenant
    AND case_id = :case
    AND delivery_method = 'certified_mail'
    AND delivery_status IS DISTINCT FROM 'delivered'
    ORDER BY created_at
    LIMIT 1
");

$stmt->execute([
    ':tenant' => $TENANT_ID,
    ':case'   => $CASE_ID
]);

$SERVICE_TASK_ID = $stmt->fetchColumn();

if (!$SERVICE_TASK_ID) {
    throw new Exception("Certified mail service task not found for case $CASE_ID");
}

echo "SERVICE_TASK_ID = $SERVICE_TASK_ID\n";


/*
|--------------------------------------------------------------------------
| 4. Mark Certified Mail Delivered
|--------------------------------------------------------------------------
*/

api(
    "POST",
    "/service-tasks/$SERVICE_TASK_ID/certified-mail/delivered",
    [
        "delivered_at" => gmdate("c"),
        "tracking_number" => "USPS123456789",
        "proof_case_document_id" => $AFFIDAVIT_CASE_DOCUMENT_ID
    ]
);

echo "Certified mail delivery recorded\n";


/*
|--------------------------------------------------------------------------
| 5. Verify Timeline
|--------------------------------------------------------------------------
*/

logStep("Verify Timeline");

printEventTimeline($pdo, $TENANT_ID, $CASE_ID);

/*
|--------------------------------------------------------------------------
| 11. Create Legal Action
|--------------------------------------------------------------------------
*/

logStep("Create Legal Action");

$legal = api(
    "POST",
    "/cases/$CASE_ID/actions/az_forcible_detainer/initiate",
    [
        "origin" => "system"
    ]
);

$LEGAL_ACTION_ID = requireStringField($legal, ['legal_action_id'], 'legal_action_id');

echo "LEGAL_ACTION_ID = $LEGAL_ACTION_ID\n";
printEventTimeline($pdo, $TENANT_ID, $CASE_ID);

/*
|--------------------------------------------------------------------------
| Resolve Venue (Passive Phase — deterministic wait)
|--------------------------------------------------------------------------
*/

logStep("Resolve Venue");

$maxAttempts = 20;

$resolved = false;

for ($i = 0; $i < $maxAttempts; $i++) {

    $stmt = $pdo->prepare("
        SELECT 1
        FROM case_venue_assignments
        WHERE case_id = :case
        LIMIT 1
    ");

    $stmt->execute([
        ':case'   => $CASE_ID
    ]);

    if ($stmt->fetchColumn()) {
        echo "Venue assignment created\n";
        $resolved = true;
        break;
    }

    sleep(1);
}

if (!$resolved) {
    throw new Exception("Venue resolution timeout");
}

$stmt = $pdo->prepare("
    SELECT
        court_id,
        jurisdiction_unit_id
    FROM case_venue_assignments
    WHERE case_id = :case
    ORDER BY assigned_at DESC
    LIMIT 1
");

$stmt->execute([
    ':case'   => $CASE_ID
]);

$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    throw new Exception("Venue assignment missing");
}

if (empty($row['court_id'])) {
    throw new Exception("Court not resolved");
}

printEventTimeline($pdo, $TENANT_ID, $CASE_ID);


/*
|--------------------------------------------------------------------------
| 12. Ready To File
|--------------------------------------------------------------------------
*/

logStep("Ready To File");

$ready = api(
    "POST",
    "/cases/$CASE_ID/legal-actions/$LEGAL_ACTION_ID/ready-to-file"
);

print_r($ready);
printEventTimeline($pdo, $TENANT_ID, $CASE_ID);

/*
|--------------------------------------------------------------------------
| 13. Prepare For Filing
|--------------------------------------------------------------------------
*/

logStep("Prepare For Filing");

$prepared = api(
    "POST",
    "/cases/$CASE_ID/legal-actions/$LEGAL_ACTION_ID/prepare-for-filing"
);

print_r($prepared);

echo "\n---------------------------------\n";
echo "Golden Case Ready For Filing\n";
echo "CASE_ID: $CASE_ID\n";
echo "LEGAL_ACTION_ID: $LEGAL_ACTION_ID\n";
echo "---------------------------------\n";

printEventTimeline($pdo, $TENANT_ID, $CASE_ID);