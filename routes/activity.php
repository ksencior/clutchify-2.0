<?php

if ($action === 'get_activity_feed') {
    $userId = requireUserId();

    $limit = (int)($_GET['limit'] ?? 12);

    if ($limit < 1) {
        $limit = 12;
    }

    if ($limit > 30) {
        $limit = 30;
    }

    $stmt = $pdo->prepare("
        SELECT
            ae.id,
            ae.type,
            ae.title,
            ae.message,
            ae.target_type,
            ae.target_id,
            ae.metadata,
            ae.visibility,
            ae.created_at,

            u.id AS actor_id,
            u.username AS actor_username,
            p.avatar AS actor_avatar
        FROM activity_events ae
        LEFT JOIN users u ON u.id = ae.actor_user_id
        LEFT JOIN players p ON p.user_id = u.id
        WHERE
            ae.visibility = 'public'
            OR (
                ae.visibility = 'friends'
                AND ae.actor_user_id IS NOT NULL
                AND (
                    ae.actor_user_id = ?
                    OR EXISTS (
                        SELECT 1
                        FROM friendships f
                        WHERE f.status = 'accepted'
                          AND (
                              (
                                  f.requester_id = ?
                                  AND f.addressee_id = ae.actor_user_id
                              )
                              OR
                              (
                                  f.addressee_id = ?
                                  AND f.requester_id = ae.actor_user_id
                              )
                          )
                    )
                )
            )
        ORDER BY ae.created_at DESC
        LIMIT {$limit}
    ");

    $stmt->execute([
        $userId,
        $userId,
        $userId
    ]);

    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($items as &$item) {
        $item['id'] = (int)$item['id'];
        $item['target_id'] = $item['target_id'] !== null ? (int)$item['target_id'] : null;
        $item['actor_id'] = $item['actor_id'] !== null ? (int)$item['actor_id'] : null;

        $decoded = json_decode($item['metadata'] ?? '', true);
        $item['metadata'] = is_array($decoded) ? $decoded : [];
    }

    jsonSuccess([
        'items' => $items
    ]);
}