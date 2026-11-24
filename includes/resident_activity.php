<?php

function residentActivityEnsureTable(mysqli $conn): bool
{
    $sql = "
        CREATE TABLE IF NOT EXISTS resident_activity (
            id INT AUTO_INCREMENT PRIMARY KEY,
            resident_id INT NOT NULL,
            resident_name VARCHAR(150) NOT NULL,
            action ENUM('Logged in', 'Logged out') NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_resident_activity_resident_created (resident_id, created_at),
            INDEX idx_resident_activity_created (created_at)
        ) ENGINE=InnoDB
    ";

    if (!$conn->query($sql)) {
        error_log('Unable to create resident activity table: ' . $conn->error);
        return false;
    }

    return true;
}

function residentActivityLog(
    mysqli $conn,
    int $residentId,
    string $residentName,
    string $action
): bool {
    if (!in_array($action, ['Logged in', 'Logged out'], true)) {
        error_log('Invalid resident activity action.');
        return false;
    }

    if (!residentActivityEnsureTable($conn)) {
        return false;
    }

    $stmt = $conn->prepare("
        INSERT INTO resident_activity (resident_id, resident_name, action)
        VALUES (?, ?, ?)
    ");

    if (!$stmt) {
        error_log('Unable to prepare resident activity log: ' . $conn->error);
        return false;
    }

    $stmt->bind_param('iss', $residentId, $residentName, $action);
    $success = $stmt->execute();

    if (!$success) {
        error_log('Unable to write resident activity log: ' . $stmt->error);
    }

    $stmt->close();
    return $success;
}
