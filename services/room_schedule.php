<?php
function schoolDayCode(string $date): string {
    return ['Mon'=>'M','Tue'=>'T','Wed'=>'W','Thu'=>'Th','Fri'=>'F','Sat'=>'S'][(new DateTimeImmutable($date))->format('D')] ?? '';
}
function regularClassSlots(PDO $pdo, string $date, ?string $roomCode = null): array {
    $day = schoolDayCode($date);
    if ($day === '') return [];
    try {
        $sql = 'SELECT room_code,start_time AS start,end_time AS end FROM class_schedules WHERE day_of_week=?';
        $params = [$day];
        if ($roomCode !== null) { $sql .= ' AND room_code=?'; $params[] = $roomCode; }
        $stmt = $pdo->prepare($sql . ' ORDER BY start_time,end_time');
        $stmt->execute($params);
        return $stmt->fetchAll();
    } catch (PDOException $error) {
        if (($error->errorInfo[1] ?? 0) === 1146) return [];
        throw $error;
    }
}
function hasRegularClassConflict(PDO $pdo, string $roomCode, string $date, string $start, string $end = ''): bool {
    foreach (regularClassSlots($pdo, $date, $roomCode) as $slot) {
        if (($end === '' ? $slot['start'] <= $start : $slot['start'] < $end) && $slot['end'] > $start) return true;
    }
    return false;
}
