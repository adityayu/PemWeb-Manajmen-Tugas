<?php
require_once 'includes/auth.php';
requireLogin();

$user = getCurrentUser();

// Handle mark as read
if (isset($_POST['mark_read'])) {
    $notification_id = $_POST['notification_id'];
    $db->execute("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?", [$notification_id, $user['id']]);
    header('Location: notifikasi.php');
    exit;
}

// Handle mark all as read
if (isset($_POST['mark_all_read'])) {
    $db->execute("UPDATE notifications SET is_read = 1 WHERE user_id = ?", [$user['id']]);
    header('Location: notifikasi.php');
    exit;
}

// Handle delete notification
if (isset($_POST['delete_notification'])) {
    $notification_id = $_POST['notification_id'];
    $db->execute("DELETE FROM notifications WHERE id = ? AND user_id = ?", [$notification_id, $user['id']]);
    header('Location: notifikasi.php');
    exit;
}

// Get filter
$filter = $_GET['filter'] ?? 'all';
$where_clause = "WHERE user_id = ?";
$params = [$user['id']];

switch ($filter) {
    case 'unread':
        $where_clause .= " AND is_read = 0";
        break;
    case 'warning':
        $where_clause .= " AND type = 'warning'";
        break;
    case 'info':
        $where_clause .= " AND type = 'info'";
        break;
    case 'success':
        $where_clause .= " AND type = 'success'";
        break;
    case 'danger':
        $where_clause .= " AND type = 'danger'";
        break;
}

// Get notifications with pagination
$page = $_GET['page'] ?? 1;
$per_page = 10;
$offset = ($page - 1) * $per_page;

$notifications = $db->select("
    SELECT * FROM notifications 
    $where_clause 
    ORDER BY created_at DESC 
    LIMIT $per_page OFFSET $offset
", $params);

$total_notifications = $db->selectOne("SELECT COUNT(*) as count FROM notifications $where_clause", $params)['count'];
$total_pages = ceil($total_notifications / $per_page);

// Get unread count
$unread_count = $db->selectOne("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0", [$user['id']])['count'];

// Auto-generate notifications for demo (only if there are no notifications)
$existing_notifications = $db->selectOne("SELECT COUNT(*) as count FROM notifications WHERE user_id = ?", [$user['id']])['count'];
if ($existing_notifications == 0) {
    // Generate some sample notifications
    if (isAdmin()) {
        $sample_notifications = [
            ['title' => 'Tugas Baru Dikumpulkan', 'message' => 'Mahasiswa Budi Santoso telah mengumpulkan tugas "Project Website Portofolio"', 'type' => 'info'],
            ['title' => 'Deadline Mendekati', 'message' => 'Tugas "ERD Sistem Perpustakaan" akan berakhir dalam 2 hari', 'type' => 'warning'],
            ['title' => 'Pengumpulan Terlambat', 'message' => 'Ada 3 mahasiswa yang terlambat mengumpulkan tugas "Analisis Framework PHP"', 'type' => 'danger']
        ];
    } else {
        $sample_notifications = [
            ['title' => 'Tugas Baru Tersedia', 'message' => 'Tugas baru "Project Website Portofolio" telah ditambahkan untuk mata kuliah Pemrograman Web', 'type' => 'info'],
            ['title' => 'Deadline Mendekati', 'message' => 'Tugas "ERD Sistem Perpustakaan" akan berakhir dalam 2 hari. Jangan lupa untuk mengumpulkan!', 'type' => 'warning'],
            ['title' => 'Nilai Sudah Keluar', 'message' => 'Nilai untuk tugas "Analisis Framework PHP" sudah tersedia. Nilai Anda: 85', 'type' => 'success']
        ];
    }
    
    foreach ($sample_notifications as $notif) {
        $db->execute("INSERT INTO notifications (user_id, title, message, type) VALUES (?, ?, ?, ?)", 
            [$user['id'], $notif['title'], $notif['message'], $notif['type']]);
    }
    
    // Refresh page to show new notifications
    header('Location: notifikasi.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifikasi - Sistem Tugas Mahasiswa</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f8f9fa;
        }
        .navbar {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .sidebar {
            background: white;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            min-height: calc(100vh - 120px);
        }
        .nav-link {
            color: #666;
            padding: 12px 20px;
            border-radius: 8px;
            margin: 5px 0;
            transition: all 0.3s ease;
        }
        .nav-link:hover, .nav-link.active {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        .card {
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            border: none;
        }
        .card-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px 15px 0 0 !important;
        }
        .notification-item {
            border-left: 4px solid;
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            transition: all 0.3s ease;
            margin-bottom: 15px;
        }
        .notification-item:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        .notification-item.unread {
            background: #f8f9ff;
        }
        .notification-item.info { border-left-color: #17a2b8; }
        .notification-item.success { border-left-color: #28a745; }
        .notification-item.warning { border-left-color: #ffc107; }
        .notification-item.danger { border-left-color: #dc3545; }
        
        .notification-header {
            display: flex;
            justify-content: between;
            align-items: flex-start;
        }
        .notification-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 18px;
        }
        .notification-icon.info { background: #17a2b8; }
        .notification-icon.success { background: #28a745; }
        .notification-icon.warning { background: #ffc107; }
        .notification-icon.danger { background: #dc3545; }
        
        .filter-tabs {
            background: white;
            border-radius: 15px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            margin-bottom: 20px;
        }
        .filter-tab {
            padding: 12px 20px;
            border: none;
            background: none;
            color: #666;
            border-radius: 10px;
            margin: 5px;
            transition: all 0.3s ease;
        }
        .filter-tab.active, .filter-tab:hover {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        .unread-badge {
            background: #dc3545;
            color: white;
            border-radius: 12px;
            padding: 2px 8px;
            font-size: 0.75em;
            font-weight: bold;
        }
        .pagination {
            justify-content: center;
        }
        .page-link {
            border-radius: 8px;
            margin: 0 2px;
            border: none;
            color: #667eea;
        }
        .page-item.active .page-link {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
        }
    </style>
</head>
<body>
    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container-fluid">
            <a class="navbar-brand" href="dashboard.php">
                <i class="fas fa-graduation-cap"></i> Sistem Tugas Mahasiswa
            </a>
            
            <div class="navbar-nav ms-auto">
                <div class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                        <i class="fas fa-user"></i> <?= htmlspecialchars($user['full_name']) ?>
                    </a>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="profile.php"><i class="fas fa-user-edit"></i> Profile</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </nav>

    <div class="container-fluid mt-4">
        <div class="row">
            <!-- Sidebar -->
            <div class="col-md-3 col-lg-2 mb-4">
                <div class="sidebar p-3">
                    <div class="nav flex-column">
                        <a class="nav-link" href="dashboard.php">
                            <i class="fas fa-tachometer-alt"></i> Dashboard
                        </a>
                        
                        <?php if (isAdmin()): ?>
                            <a class="nav-link" href="mata-kuliah.php">
                                <i class="fas fa-book"></i> Mata Kuliah
                            </a>
                            <a class="nav-link" href="tugas.php">
                                <i class="fas fa-tasks"></i> Kelola Tugas
                            </a>
                            <a class="nav-link" href="penilaian.php">
                                <i class="fas fa-star"></i> Penilaian
                            </a>
                            <a class="nav-link" href="mahasiswa.php">
                                <i class="fas fa-users"></i> Mahasiswa
                            </a>
                        <?php else: ?>
                            <a class="nav-link" href="mata-kuliah.php">
                                <i class="fas fa-book"></i> Mata Kuliah Saya
                            </a>
                            <a class="nav-link" href="tugas-saya.php">
                                <i class="fas fa-tasks"></i> Tugas Saya
                            </a>
                            <a class="nav-link" href="nilai-saya.php">
                                <i class="fas fa-star"></i> Nilai Saya
                            </a>
                            <a class="nav-link" href="leaderboard.php">
                                <i class="fas fa-trophy"></i> Leaderboard
                            </a>
                        <?php endif; ?>
                        
                        <a class="nav-link active" href="notifikasi.php">
                            <i class="fas fa-bell"></i> Notifikasi
                            <?php if ($unread_count > 0): ?>
                                <span class="unread-badge"><?= $unread_count ?></span>
                            <?php endif; ?>
                        </a>
                    </div>
                </div>
            </div>

            <!-- Main Content -->
            <div class="col-md-9 col-lg-10">
                <!-- Page Header -->
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h2><i class="fas fa-bell text-primary"></i> Notifikasi</h2>
                        <p class="text-muted mb-0"><?= $unread_count ?> notifikasi belum dibaca dari total <?= $total_notifications ?> notifikasi</p>
                    </div>
                    <?php if ($unread_count > 0): ?>
                        <form method="POST" class="d-inline">
                            <button type="submit" name="mark_all_read" class="btn btn-outline-primary">
                                <i class="fas fa-check-double"></i> Tandai Semua Dibaca
                            </button>
                        </form>
                    <?php endif; ?>
                </div>

                <!-- Filter Tabs -->
                <div class="filter-tabs p-3">
                    <div class="d-flex flex-wrap">
                        <button class="filter-tab <?= $filter == 'all' ? 'active' : '' ?>" 
                                onclick="location.href='?filter=all'">
                            <i class="fas fa-list"></i> Semua
                        </button>
                        <button class="filter-tab <?= $filter == 'unread' ? 'active' : '' ?>" 
                                onclick="location.href='?filter=unread'">
                            <i class="fas fa-envelope"></i> Belum Dibaca
                            <?php if ($unread_count > 0): ?>
                                <span class="unread-badge"><?= $unread_count ?></span>
                            <?php endif; ?>
                        </button>
                        <button class="filter-tab <?= $filter == 'info' ? 'active' : '' ?>" 
                                onclick="location.href='?filter=info'">
                            <i class="fas fa-info-circle"></i> Info
                        </button>
                        <button class="filter-tab <?= $filter == 'success' ? 'active' : '' ?>" 
                                onclick="location.href='?filter=success'">
                            <i class="fas fa-check-circle"></i> Sukses
                        </button>
                        <button class="filter-tab <?= $filter == 'warning' ? 'active' : '' ?>" 
                                onclick="location.href='?filter=warning'">
                            <i class="fas fa-exclamation-triangle"></i> Peringatan
                        </button>
                        <button class="filter-tab <?= $filter == 'danger' ? 'active' : '' ?>" 
                                onclick="location.href='?filter=danger'">
                            <i class="fas fa-exclamation-circle"></i> Penting
                        </button>
                    </div>
                </div>

                <!-- Notifications List -->
                <?php if (empty($notifications)): ?>
                    <div class="card">
                        <div class="card-body text-center py-5">
                            <i class="fas fa-bell-slash fa-4x text-muted mb-3"></i>
                            <h4>Tidak ada notifikasi</h4>
                            <p class="text-muted">
                                <?php if ($filter == 'unread'): ?>
                                    Semua notifikasi sudah dibaca.
                                <?php elseif ($filter != 'all'): ?>
                                    Tidak ada notifikasi dengan filter "<?= ucfirst($filter) ?>".
                                <?php else: ?>
                                    Belum ada notifikasi untuk Anda.
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>
                <?php else: ?>
                    <?php foreach ($notifications as $notification): ?>
                        <div class="notification-item <?= $notification['type'] ?> <?= !$notification['is_read'] ? 'unread' : '' ?> p-4">
                            <div class="row">
                                <div class="col-auto">
                                    <div class="notification-icon <?= $notification['type'] ?>">
                                        <?php
                                        $icons = [
                                            'info' => 'fas fa-info-circle',
                                            'success' => 'fas fa-check-circle',
                                            'warning' => 'fas fa-exclamation-triangle',
                                            'danger' => 'fas fa-exclamation-circle'
                                        ];
                                        ?>
                                        <i class="<?= $icons[$notification['type']] ?? 'fas fa-bell' ?>"></i>
                                    </div>
                                </div>
                                <div class="col">
                                    <div class="d-flex justify-content-between align-items-start mb-2">
                                        <h6 class="mb-0 fw-bold"><?= htmlspecialchars($notification['title']) ?></h6>
                                        <div class="d-flex align-items-center">
                                            <?php if (!$notification['is_read']): ?>
                                                <span class="badge bg-primary me-2">Baru</span>
                                            <?php endif; ?>
                                            <small class="text-muted">
                                                <?= date('d/m/Y H:i', strtotime($notification['created_at'])) ?>
                                            </small>
                                        </div>
                                    </div>
                                    <p class="mb-3 text-muted"><?= htmlspecialchars($notification['message']) ?></p>
                                    <div class="d-flex gap-2">
                                        <?php if (!$notification['is_read']): ?>
                                            <form method="POST" class="d-inline">
                                                <input type="hidden" name="notification_id" value="<?= $notification['id'] ?>">
                                                <button type="submit" name="mark_read" class="btn btn-sm btn-outline-primary">
                                                    <i class="fas fa-check"></i> Tandai Dibaca
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                        <form method="POST" class="d-inline" onsubmit="return confirm('Yakin ingin menghapus notifikasi ini?')">
                                            <input type="hidden" name="notification_id" value="<?= $notification['id'] ?>">
                                            <button type="submit" name="delete_notification" class="btn btn-sm btn-outline-danger">
                                                <i class="fas fa-trash"></i> Hapus
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>

                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                        <nav aria-label="Pagination Navigation" class="mt-4">
                            <ul class="pagination">
                                <?php if ($page > 1): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="?filter=<?= $filter ?>&page=<?= $page - 1 ?>">
                                            <i class="fas fa-chevron-left"></i> Sebelumnya
                                        </a>
                                    </li>
                                <?php endif; ?>

                                <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                                    <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                                        <a class="page-link" href="?filter=<?= $filter ?>&page=<?= $i ?>"><?= $i ?></a>
                                    </li>
                                <?php endfor; ?>

                                <?php if ($page < $total_pages): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="?filter=<?= $filter ?>&page=<?= $page + 1 ?>">
                                            Selanjutnya <i class="fas fa-chevron-right"></i>
                                        </a>
                                    </li>
                                <?php endif; ?>
                            </ul>
                        </nav>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
    <script>
        // Highlight navigation
        document.addEventListener('DOMContentLoaded', function() {
            // Auto-refresh untuk notifikasi baru setiap 30 detik
            setInterval(function() {
                // Hanya refresh jika ada notifikasi baru (bisa diimplementasi dengan AJAX)
                // location.reload();
            }, 30000);

            // Add click handlers for notification items
            document.querySelectorAll('.notification-item.unread').forEach(function(item) {
                item.addEventListener('click', function(e) {
                    if (e.target.type !== 'submit' && e.target.tagName !== 'BUTTON') {
                        // Mark as read when clicked (could implement with AJAX)
                        const form = item.querySelector('form[method="POST"]');
                        if (form && form.querySelector('input[name="mark_read"]')) {
                            // Could submit form via AJAX instead of full page reload
                        }
                    }
                });
            });
        });

        // Smooth scroll for better UX
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    target.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                }
            });
        });
    </script>
</body>
</html>
