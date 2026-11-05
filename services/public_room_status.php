<?php
require_once __DIR__ . '/room_schedule.php';

/** Public projection: room details and approved time slots only. */
function publicRoomStatus(PDO $pdo): array
{
    $columns = $pdo->query('SHOW COLUMNS FROM rooms')->fetchAll(PDO::FETCH_COLUMN);
    $code = in_array('room_code', $columns, true) ? 'room_code' : 'room_id';
    $clock = $pdo->query('SELECT CURDATE() AS today, CURRENT_TIMESTAMP AS checked_at, CURTIME() AS clock_time')->fetch();
    $stmt = $pdo->prepare("SELECT room_id, time_start, time_end FROM reservations
        WHERE res_date = ? AND status = 'Approved' ORDER BY time_start, time_end");
    $stmt->execute([$clock['today']]);
    $schedules = [];
    foreach ($stmt as $slot) {
        $schedules[(int) $slot['room_id']][] = [
            'start' => $slot['time_start'], 'end' => $slot['time_end'],
            'is_current' => $slot['time_start'] <= $clock['clock_time'] && $slot['time_end'] > $clock['clock_time'],
        ];
    }
    $classes = [];
    foreach (regularClassSlots($pdo, $clock['today']) as $slot) {
        $classes[strtoupper($slot['room_code'])][] = ['start'=>$slot['start'], 'end'=>$slot['end'], 'kind'=>'class',
            'is_current'=>$slot['start'] <= $clock['clock_time'] && $slot['end'] > $clock['clock_time']];
    }
    $rows = $pdo->query("SELECT id, $code AS room_code, room_name, type, capacity, location, status FROM rooms ORDER BY $code");
    $rooms = [];
    foreach ($rows as $row) {
        $row['id'] = (int) $row['id'];
        $row['capacity'] = (int) $row['capacity'];
        $row['schedule'] = array_merge($schedules[$row['id']] ?? [], $classes[strtoupper($row['room_code'])] ?? []);
        usort($row['schedule'], fn($a,$b) => strcmp($a['start'], $b['start']));
        $row['current_reservation'] = null;
        foreach ($row['schedule'] as $slot) {
            if ($slot['is_current']) { $row['current_reservation'] = $slot; break; }
        }
        if ($row['status'] === 'Available' && $row['current_reservation'] !== null) {
            $row['status'] = 'Occupied';
        }
        $rooms[] = $row;
    }
    return ['rooms' => $rooms, 'date' => $clock['today'], 'checked_at' => $clock['checked_at']];
}
