<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../services/damage_disposal.php';
requireLogin(['admin', 'maintenance']);
$pdo = getDB();
try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'eligible_equipment') {
        jsonResponse(true, 'OK', ['equipment'=>disposalEligibleEquipment($pdo), 'reported_by'=>getUserName()]);
    }
    if ($_SERVER['REQUEST_METHOD'] === 'GET') jsonResponse(true, 'OK', [
        'pending' => disposalRecords($pdo, 'Pending'), 'approved' => disposalRecords($pdo),
        'rejected' => disposalRecords($pdo, 'Rejected'), 'can_review' => getRole() === 'admin',
    ]);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $body = str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') ? bodyJson() : $_POST;
        foreach (['id','decision','note','action','equipment_id','reason'] as $field) {
            if (isset($body[$field]) && !is_scalar($body[$field])) jsonResponse(false, 'Invalid input field: ' . $field, [], 422);
        }
        if (($body['action'] ?? '') === 'request') {
            $equipmentId = filter_var($body['equipment_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$equipmentId || $equipmentId < 1) jsonResponse(false, 'A valid equipment selection is required.', [], 422);
            $result = requestEquipmentDisposal($pdo, $equipmentId, (string)($body['reason'] ?? ''), getUserId());
            logActivity($pdo, 'REQUEST_DISPOSAL', 'disposal:' . $result['disposal_request_id']);
            jsonResponse(true, 'Disposal request submitted for Admin approval.', $result, 201);
        }
        requireLogin('admin');
        $id = filter_var($body['id'] ?? null, FILTER_VALIDATE_INT);
        if (!$id || $id < 1) jsonResponse(false, 'A valid disposal request ID is required.', [], 422);
        $decision = (string)($body['decision'] ?? '');
        $note = trim((string)($body['note'] ?? ''));
        if (mb_strlen($note) > 2000) jsonResponse(false, 'Review notes must be at most 2000 characters.', [], 422);
        reviewDisposal($pdo, $id, $decision, $note, getUserId(), getRole());
        logActivity($pdo, 'REVIEW_DISPOSAL', 'disposal:' . $id, $decision);
        jsonResponse(true, 'Disposal request reviewed.');
    }
    jsonResponse(false, 'Method not allowed.', [], 405);
} catch (InvalidArgumentException $e) { jsonResponse(false, $e->getMessage(), [], 422);
} catch (OutOfBoundsException $e) { jsonResponse(false, $e->getMessage(), [], 404);
} catch (UnexpectedValueException $e) { jsonResponse(false, $e->getMessage(), [], 409);
} catch (DomainException $e) { jsonResponse(false, $e->getMessage(), [], 403);
} catch (Throwable $e) { error_log($e->getMessage()); jsonResponse(false, 'Unable to process disposal requests.', [], 500); }
