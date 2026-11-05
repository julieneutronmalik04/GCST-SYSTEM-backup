<?php
require_once __DIR__ . '/input_validation.php';
require_once __DIR__ . '/reservations.php';
function saveClassSchedule(PDO $pdo, array $body, int $actor, ?int $id = null): array {
    foreach (['year_section','subject_name','start_time','end_time','room_code','day_of_week','day'] as $field) if (isset($body[$field]) && !is_string($body[$field])) throw new InvalidArgumentException('Invalid schedule field: ' . $field);
    $section = trim((string) ($body['year_section'] ?? ''));
    $subject = trim((string) ($body['subject_name'] ?? ''));
    $raw = $body['days'] ?? [$body['day_of_week'] ?? $body['day'] ?? ''];
    $days = is_array($raw) ? $raw : explode(',', (string) $raw);
    foreach ($days as $value) if (!is_string($value)) throw new InvalidArgumentException('Choose valid schedule days.');
    $days = array_values(array_unique(array_map('trim', $days)));
    $allowed = ['M'=>2,'T'=>3,'W'=>4,'Th'=>5,'F'=>6,'S'=>7];
    if (!$days || array_diff($days, array_keys($allowed)) || ($id && count($days) !== 1)) throw new InvalidArgumentException('Choose valid schedule days.');
    if ($section === '' || $subject === '' || mb_strlen($section)>80 || mb_strlen($subject)>160) throw new InvalidArgumentException('A valid section and subject are required.');
    $start = strictTime((string) ($body['start_time'] ?? ''), 'Start time');
    $end = strictTime((string) ($body['end_time'] ?? ''), 'End time');
    if ($start >= $end) throw new InvalidArgumentException('End time must be after start time.');
    $code = validateAssetCode(trim((string) ($body['room_code'] ?? '')));
    $oldCode = null;
    if ($id) {
        $stmt = $pdo->prepare('SELECT room_code FROM class_schedules WHERE id=?');
        $stmt->execute([$id]);
        $oldCode = $stmt->fetchColumn();
        if (!$oldCode) throw new InvalidArgumentException('Schedule not found.');
    }
    $pdo->beginTransaction();
    try {
        $codes = array_values(array_unique(array_filter([$code,$oldCode])));
        sort($codes);
        $rooms = [];
        foreach ($codes as $ref) $rooms[$ref] = reservationRoom($pdo, $ref, true);
        if ($id) {
            $current = $pdo->prepare('SELECT room_code FROM class_schedules WHERE id=? FOR UPDATE');
            $current->execute([$id]);
            if ($current->fetchColumn() !== $oldCode) throw new DomainException('This schedule changed. Refresh and try again.');
        }
        $room = $rooms[$code];
        if ($room['status'] === 'Under Maintenance') throw new InvalidArgumentException('The selected room is under maintenance.');
        foreach ($days as $day) {
            $stmt = $pdo->prepare('SELECT id FROM class_schedules WHERE room_code=? AND day_of_week=? AND id<>? AND start_time<? AND end_time>? LIMIT 1');
            $stmt->execute([$code,$day,$id ?? 0,$end,$start]);
            if ($stmt->fetchColumn()) throw new DomainException('Another regular class overlaps this room and time.');
            $stmt = $pdo->prepare("SELECT id FROM reservations WHERE room_code=? AND status='Approved'
                AND (res_date>CURDATE() OR (res_date=CURDATE() AND time_end>CURTIME()))
                AND DAYOFWEEK(res_date)=? AND time_start<? AND time_end>? LIMIT 1");
            $stmt->execute([$code,$allowed[$day],$end,$start]);
            if ($stmt->fetchColumn()) throw new DomainException('An approved reservation overlaps this recurring class. Reschedule it before adding this class.');
        }
        if ($id) {
            $pdo->prepare('UPDATE class_schedules SET year_section=?,subject_name=?,day_of_week=?,start_time=?,end_time=?,room_code=?,room_name=? WHERE id=?')
                ->execute([$section,$subject,$days[0],$start,$end,$code,$room['room_name'],$id]);
        } else {
            $stmt = $pdo->prepare('INSERT INTO class_schedules(year_section,subject_name,day_of_week,start_time,end_time,room_code,room_name,created_by) VALUES(?,?,?,?,?,?,?,?)');
            foreach ($days as $day) $stmt->execute([$section,$subject,$day,$start,$end,$code,$room['room_name'],$actor]);
        }
        $promoted = $oldCode ? promoteReservationWaiters($pdo, $rooms[$oldCode]) : [];
        $pdo->commit();
        notifyClassSlotReleased($pdo, $promoted);
        return $promoted;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}
function deleteClassSchedule(PDO $pdo, int $id): array {
    $stmt = $pdo->prepare('SELECT room_code FROM class_schedules WHERE id=?');
    $stmt->execute([$id]);
    $code = $stmt->fetchColumn();
    if (!$code) throw new InvalidArgumentException('Schedule not found.');
    $pdo->beginTransaction();
    try {
        $room = reservationRoom($pdo, $code, true);
        $current = $pdo->prepare('SELECT room_code FROM class_schedules WHERE id=? FOR UPDATE');
        $current->execute([$id]);
        if ($current->fetchColumn() !== $code) throw new DomainException('This schedule changed. Refresh and try again.');
        $pdo->prepare('DELETE FROM class_schedules WHERE id=?')->execute([$id]);
        $promoted = promoteReservationWaiters($pdo, $room);
        $pdo->commit();
        notifyClassSlotReleased($pdo, $promoted);
        return $promoted;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}
function notifyClassSlotReleased(PDO $pdo, array $rows): void {
    foreach ($rows as $row) {
        try {
            $pdo->prepare("INSERT INTO faculty_notifications(user_id,type,title,body,link_ref) VALUES(?,'reservation_pending','Class slot released',?,'my-reservations')")
                ->execute([$row['faculty_id'],'Your request for ' . $row['room_name'] . ' is Pending admin review after a class schedule was changed.']);
        } catch (Throwable $error) { error_log($error->getMessage()); }
    }
}
