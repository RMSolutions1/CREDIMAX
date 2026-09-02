<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\App;
use App\Core\View;

final class NotificationController
{
    public function index(): void
    {
        require_auth();
        $rows = App::db()->fetchAll(
            'SELECT * FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT 100',
            [auth_id()]
        );
        App::db()->query('UPDATE notifications SET is_read = 1 WHERE user_id = ?', [auth_id()]);
        View::render('notifications/index', ['title' => 'Notificaciones', 'rows' => $rows]);
    }
}
