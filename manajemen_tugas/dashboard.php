<?php
require_once 'includes/auth.php';
requireLogin();

$user = getCurrentUser();

// Get statistics berdasarkan role
if (isAdmin()) {
    // Statistics untuk admin/dosen
    $stats = [
        'total_mata_kuliah' => $db->selectOne("SELECT COUNT(*) as count FROM mata_kuliah WHERE dosen_id = ?", [$user['id']])['count'],
        'total_tugas' => $db->selectOne("
            SELECT COUNT(*) as count FROM tugas t 
            JOIN mata_kuliah mk ON t.mata_kuliah_id = mk.id 
            WHERE mk.dosen_id = ?", [$user['id']])['count'],
        'total_pengumpulan' => $db->selectOne("
            SELECT COUNT(*) as count FROM pengumpulan_tugas pt
            JOIN tugas t ON pt.tugas_id = t.id
            JOIN mata_kuliah mk ON t.mata_kuliah_id = mk.id
            WHERE mk.dosen_id = ?", [$user['id']])['count'],
        'belum_dinilai' => $db->selectOne("
            SELECT COUNT(*) as count FROM pengumpulan_tugas pt
            JOIN tugas t ON pt.tugas_id = t.id
            JOIN mata_kuliah mk ON t.mata_kuliah_id = mk.id
            WHERE mk.dosen_id = ? AND pt.nilai IS NULL", [$user['id']])['count']
    ];

    // Recent submissions yang belum dinilai
    $recent_submissions = $db->select("
        SELECT pt.*, t.judul as tugas_judul, mk.nama_mk, u.full_name as mahasiswa_nama, u.nim
        FROM pengumpulan_tugas pt
        JOIN tugas t ON pt.tugas_id = t.id
        JOIN mata_kuliah mk ON t.mata_kuliah_id = mk.id
        JOIN users u ON pt.mahasiswa_id = u.id
        WHERE mk.dosen_id = ? AND pt.nilai IS NULL
        ORDER BY pt.submitted_at DESC
        LIMIT 5", [$user['id']]);

} else {
    // Statistics untuk mahasiswa
    $stats = [
        'mata_kuliah_diambil' => $db->selectOne("SELECT COUNT(*) as count FROM enrollments WHERE mahasiswa_id = ?", [$user['id']])['count'],
        'total_tugas' => $db->selectOne("
            SELECT COUNT(*) as count FROM tugas t
            JOIN enrollments e ON t.mata_kuliah_id = e.mata_kuliah_id
            WHERE e.mahasiswa_id = ?", [$user['id']])['count'],
        'tugas_dikumpulkan' => $db->selectOne("SELECT COUNT(*) as count FROM pengumpulan_tugas WHERE mahasiswa_id = ?", [$user['id']])['count'],
        'tugas_dinilai' => $db->selectOne("SELECT COUNT(*) as count FROM pengumpulan_tugas WHERE mahasiswa_id = ? AND nilai IS NOT NULL", [$user['id']])['count']
    ];

    // Tugas yang belum dikumpulkan
    $pending_assignments = $db->select("
        SELECT t.*, mk.nama_mk, mk.kode_mk
        FROM tugas t
        JOIN mata_kuliah mk ON t.mata_kuliah_id = mk.id
        JOIN enrollments e ON mk.id = e.mata_kuliah_id
        LEFT JOIN pengumpulan_tugas pt ON (t.id = pt.tugas_id AND pt.mahasiswa_id = ?)
        WHERE e.mahasiswa_id = ? AND pt.id IS NULL AND t.deadline > NOW() AND t.is_active = 1
        ORDER BY t.deadline ASC
        LIMIT 5", [$user['id'], $user['id']]);

    // Recent grades
    $recent_grades = $db->select("
        SELECT pt.*, t.judul as tugas_judul, mk.nama_mk, mk.kode_mk
        FROM pengumpulan_tugas pt
        JOIN tugas t ON pt.tugas_id = t.id
        JOIN mata_kuliah mk ON t.mata_kuliah_id = mk.id
        WHERE pt.mahasiswa_id = ? AND pt.nilai IS NOT NULL
        ORDER BY pt.graded_at DESC
        LIMIT 5", [$user['id']]);
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Sistem Tugas Mahasiswa</title>
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
        .stat-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            transition: transform 0.3s ease;
            border-left: 4px solid;
        }
        .stat-card:hover {
            transform: translateY(-5px);
        }
        .stat-card.primary { border-left-color: #007bff; }
        .stat-card.success { border-left-color: #28a745; }
        .stat-card.warning { border-left-color: #ffc107; }
        .stat-card.danger { border-left-color: #dc3545; }
        
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
        .deadline-badge {
            font-size: 0.8em;
        }
        .grade-badge {
            font-size: 1.1em;
            font-weight: bold;
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
        .welcome-section {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px;
            padding: 2rem;
            margin-bottom: 2rem;
        }
    </style>
</head>
<body>
    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container-fluid">
            <a class="navbar-brand" href="#">
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
                        <a class="nav-link active" href="dashboard.php">
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
                        
                        <a class="nav-link" href="notifikasi.php">
                            <i class="fas fa-bell"></i> Notifikasi
                        </a>
                    </div>
                </div>
            </div>

            <!-- Main Content -->
            <div class="col-md-9 col-lg-10">
                <!-- Welcome Section -->
                <div class="welcome-section">
                    <div class="row align-items-center">
                        <div class="col-md-8">
                            <h2><i class="fas fa-hand-wave"></i> Selamat datang, <?= htmlspecialchars($user['full_name']) ?>!</h2>
                            <p class="mb-0">
                                <?php if (isAdmin()): ?>
                                    Anda login sebagai <strong>Dosen</strong>. Kelola mata kuliah dan tugas mahasiswa Anda.
                                <?php else: ?>
                                    Anda login sebagai <strong>Mahasiswa</strong> dengan NIM: <strong><?= htmlspecialchars($user['nim']) ?></strong>
                                <?php endif; ?>
                            </p>
                        </div>
                        <div class="col-md-4 text-end">
                            <i class="fas fa-calendar-alt"></i> <?= date('d F Y, H:i') ?>
                        </div>
                    </div>
                </div>

                <!-- Statistics Cards -->
                <div class="row mb-4">
                    <?php if (isAdmin()): ?>
                        <div class="col-md-3 mb-3">
                            <div class="stat-card primary p-3">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h6 class="text-muted">Mata Kuliah</h6>
                                        <h3 class="mb-0"><?= $stats['total_mata_kuliah'] ?></h3>
                                    </div>
                                    <i class="fas fa-book fa-2x text-primary"></i>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3 mb-3">
                            <div class="stat-card success p-3">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h6 class="text-muted">Total Tugas</h6>
                                        <h3 class="mb-0"><?= $stats['total_tugas'] ?></h3>
                                    </div>
                                    <i class="fas fa-tasks fa-2x text-success"></i>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3 mb-3">
                            <div class="stat-card warning p-3">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h6 class="text-muted">Pengumpulan</h6>
                                        <h3 class="mb-0"><?= $stats['total_pengumpulan'] ?></h3>
                                    </div>
                                    <i class="fas fa-upload fa-2x text-warning"></i>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3 mb-3">
                            <div class="stat-card danger p-3">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h6 class="text-muted">Belum Dinilai</h6>
                                        <h3 class="mb-0"><?= $stats['belum_dinilai'] ?></h3>
                                    </div>
                                    <i class="fas fa-exclamation-triangle fa-2x text-danger"></i>
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="col-md-3 mb-3">
                            <div class="stat-card primary p-3">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h6 class="text-muted">Mata Kuliah</h6>
                                        <h3 class="mb-0"><?= $stats['mata_kuliah_diambil'] ?></h3>
                                    </div>
                                    <i class="fas fa-book fa-2x text-primary"></i>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3 mb-3">
                            <div class="stat-card success p-3">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h6 class="text-muted">Total Tugas</h6>
                                        <h3 class="mb-0"><?= $stats['total_tugas'] ?></h3>
                                    </div>
                                    <i class="fas fa-tasks fa-2x text-success"></i>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3 mb-3">
                            <div class="stat-card warning p-3">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h6 class="text-muted">Dikumpulkan</h6>
                                        <h3 class="mb-0"><?= $stats['tugas_dikumpulkan'] ?></h3>
                                    </div>
                                    <i class="fas fa-upload fa-2x text-warning"></i>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3 mb-3">
                            <div class="stat-card danger p-3">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h6 class="text-muted">Sudah Dinilai</h6>
                                        <h3 class="mb-0"><?= $stats['tugas_dinilai'] ?></h3>
                                    </div>
                                    <i class="fas fa-star fa-2x text-danger"></i>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Content Cards -->
                <div class="row">
                    <?php if (isAdmin()): ?>
                        <!-- Recent Submissions for Admin -->
                        <div class="col-12">
                            <div class="card">
                                <div class="card-header">
                                    <h5 class="mb-0"><i class="fas fa-clock"></i> Pengumpulan Terbaru (Belum Dinilai)</h5>
                                </div>
                                <div class="card-body">
                                    <?php if (empty($recent_submissions)): ?>
                                        <div class="text-center py-4">
                                            <i class="fas fa-check-circle fa-3x text-success mb-3"></i>
                                            <h5>Semua tugas sudah dinilai!</h5>
                                            <p class="text-muted">Tidak ada pengumpulan tugas yang belum dinilai.</p>
                                        </div>
                                    <?php else: ?>
                                        <div class="table-responsive">
                                            <table class="table table-hover">
                                                <thead>
                                                    <tr>
                                                        <th>Mahasiswa</th>
                                                        <th>Tugas</th>
                                                        <th>Mata Kuliah</th>
                                                        <th>Waktu Submit</th>
                                                        <th>Status</th>
                                                        <th>Aksi</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($recent_submissions as $submission): ?>
                                                        <tr>
                                                            <td>
                                                                <strong><?= htmlspecialchars($submission['mahasiswa_nama']) ?></strong><br>
                                                                <small class="text-muted"><?= htmlspecialchars($submission['nim']) ?></small>
                                                            </td>
                                                            <td><?= htmlspecialchars($submission['tugas_judul']) ?></td>
                                                            <td><?= htmlspecialchars($submission['nama_mk']) ?></td>
                                                            <td><?= date('d/m/Y H:i', strtotime($submission['submitted_at'])) ?></td>
                                                            <td>
                                                                <?php if ($submission['is_late']): ?>
                                                                    <span class="badge bg-danger">Terlambat</span>
                                                                <?php else: ?>
                                                                    <span class="badge bg-success">Tepat Waktu</span>
                                                                <?php endif; ?>
                                                            </td>
                                                            <td>
                                                                <a href="penilaian.php?id=<?= $submission['id'] ?>" class="btn btn-sm btn-primary">
                                                                    <i class="fas fa-star"></i> Nilai
                                                                </a>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                        <div class="text-end">
                                            <a href="penilaian.php" class="btn btn-outline-primary">
                                                Lihat Semua <i class="fas fa-arrow-right"></i>
                                            </a>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
                        <!-- Pending Assignments for Students -->
                        <div class="col-md-6 mb-4">
                            <div class="card">
                                <div class="card-header">
                                    <h5 class="mb-0"><i class="fas fa-exclamation-triangle"></i> Tugas Belum Dikumpulkan</h5>
                                </div>
                                <div class="card-body">
                                    <?php if (empty($pending_assignments)): ?>
                                        <div class="text-center py-4">
                                            <i class="fas fa-check-circle fa-3x text-success mb-3"></i>
                                            <h5>Semua tugas sudah dikumpulkan!</h5>
                                            <p class="text-muted">Tidak ada tugas yang belum dikumpulkan.</p>
                                        </div>
                                    <?php else: ?>
                                        <?php foreach ($pending_assignments as $assignment): ?>
                                            <div class="border-start border-4 border-warning ps-3 mb-3">
                                                <h6 class="mb-1"><?= htmlspecialchars($assignment['judul']) ?></h6>
                                                <p class="text-muted mb-1"><?= htmlspecialchars($assignment['nama_mk']) ?></p>
                                                <small class="deadline-badge badge bg-danger">
                                                    Deadline: <?= date('d/m/Y H:i', strtotime($assignment['deadline'])) ?>
                                                </small>
                                                <div class="mt-2">
                                                    <a href="tugas-detail.php?id=<?= $assignment['id'] ?>" class="btn btn-sm btn-primary">
                                                        <i class="fas fa-eye"></i> Lihat Detail
                                                    </a>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                        <div class="text-end">
                                            <a href="tugas-saya.php" class="btn btn-outline-primary">
                                                Lihat Semua <i class="fas fa-arrow-right"></i>
                                            </a>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Recent Grades for Students -->
                        <div class="col-md-6 mb-4">
                            <div class="card">
                                <div class="card-header">
                                    <h5 class="mb-0"><i class="fas fa-star"></i> Nilai Terbaru</h5>
                                </div>
                                <div class="card-body">
                                    <?php if (empty($recent_grades)): ?>
                                        <div class="text-center py-4">
                                            <i class="fas fa-clock fa-3x text-warning mb-3"></i>
                                            <h5>Belum ada nilai</h5>
                                            <p class="text-muted">Nilai akan muncul setelah dosen menilai tugas Anda.</p>
                                        </div>
                                    <?php else: ?>
                                        <?php foreach ($recent_grades as $grade): ?>
                                            <div class="border-start border-4 border-success ps-3 mb-3">
                                                <div class="d-flex justify-content-between align-items-start">
                                                    <div>
                                                        <h6 class="mb-1"><?= htmlspecialchars($grade['tugas_judul']) ?></h6>
                                                        <p class="text-muted mb-1"><?= htmlspecialchars($grade['nama_mk']) ?></p>
                                                        <small class="text-muted">
                                                            Dinilai: <?= date('d/m/Y H:i', strtotime($grade['graded_at'])) ?>
                                                        </small>
                                                    </div>
                                                    <span class="grade-badge badge bg-primary">
                                                        <?= number_format($grade['nilai'], 1) ?>
                                                    </span>
                                                </div>
                                                <?php if ($grade['feedback']): ?>
                                                    <div class="mt-2">
                                                        <small class="text-muted">
                                                            <i class="fas fa-comment"></i> <?= htmlspecialchars($grade['feedback']) ?>
                                                        </small>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                        <div class="text-end">
                                            <a href="nilai-saya.php" class="btn btn-outline-primary">
                                                Lihat Semua <i class="fas fa-arrow-right"></i>
                                            </a>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
    <script>
        // Auto refresh dashboard setiap 5 menit
        setTimeout(function() {
            location.reload();
        }, 300000);

        // Highlight navigation
        document.addEventListener('DOMContentLoaded', function() {
            const currentPath = window.location.pathname;
            const navLinks = document.querySelectorAll('.nav-link');
            
            navLinks.forEach(link => {
                if (link.getAttribute('href') === currentPath.split('/').pop()) {
                    link.classList.add('active');
                } else {
                    link.classList.remove('active');
                }
            });
        });
    </script>
</body>
</html>
