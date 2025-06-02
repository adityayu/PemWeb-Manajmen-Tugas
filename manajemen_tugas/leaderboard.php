<?php
require_once 'includes/auth.php';
requireLogin();

// Redirect admin ke dashboard
if (isAdmin()) {
    header('Location: dashboard.php');
    exit;
}

$user = getCurrentUser();

// Get selected mata kuliah
$selected_mk = isset($_GET['mk']) ? (int)$_GET['mk'] : 0;

// Get mata kuliah yang diambil mahasiswa untuk filter
$mata_kuliah = $db->select("
    SELECT mk.* 
    FROM mata_kuliah mk
    JOIN enrollments e ON mk.id = e.mata_kuliah_id
    WHERE e.mahasiswa_id = ?
    ORDER BY mk.nama_mk", [$user['id']]);

// Build query untuk leaderboard
$leaderboard_query = "
    SELECT 
        u.full_name,
        u.nim,
        COUNT(pt.id) as total_tugas_dikumpulkan,
        COUNT(CASE WHEN pt.nilai IS NOT NULL THEN 1 END) as total_tugas_dinilai,
        ROUND(AVG(CASE WHEN pt.nilai IS NOT NULL THEN pt.nilai END), 2) as rata_rata_nilai,
        SUM(CASE WHEN pt.nilai IS NOT NULL THEN pt.nilai ELSE 0 END) as total_nilai,
        COUNT(CASE WHEN pt.is_late = 0 THEN 1 END) as tugas_tepat_waktu,
        COUNT(CASE WHEN pt.is_late = 1 THEN 1 END) as tugas_terlambat
    FROM users u
    JOIN enrollments e ON u.id = e.mahasiswa_id
    JOIN tugas t ON e.mata_kuliah_id = t.mata_kuliah_id
    LEFT JOIN pengumpulan_tugas pt ON (t.id = pt.tugas_id AND pt.mahasiswa_id = u.id)
    WHERE u.role = 'mahasiswa'";

$params = [];

if ($selected_mk > 0) {
    $leaderboard_query .= " AND e.mata_kuliah_id = ?";
    $params[] = $selected_mk;
} else {
    // Jika tidak ada filter, ambil semua mata kuliah yang diambil user
    $mk_ids = array_column($mata_kuliah, 'id');
    if (!empty($mk_ids)) {
        $placeholders = str_repeat('?,', count($mk_ids) - 1) . '?';
        $leaderboard_query .= " AND e.mata_kuliah_id IN ($placeholders)";
        $params = array_merge($params, $mk_ids);
    }
}

$leaderboard_query .= "
    GROUP BY u.id, u.full_name, u.nim
    HAVING total_tugas_dinilai > 0
    ORDER BY rata_rata_nilai DESC, total_tugas_dinilai DESC, tugas_tepat_waktu DESC";

$leaderboard = $db->select($leaderboard_query, $params);

// Get current user rank
$current_user_rank = 0;
$current_user_data = null;
foreach ($leaderboard as $index => $student) {
    if ($student['nim'] == $user['nim']) {
        $current_user_rank = $index + 1;
        $current_user_data = $student;
        break;
    }
}

// Get mata kuliah name for title
$mk_name = "Semua Mata Kuliah";
if ($selected_mk > 0) {
    $mk_info = $db->selectOne("SELECT nama_mk FROM mata_kuliah WHERE id = ?", [$selected_mk]);
    if ($mk_info) {
        $mk_name = $mk_info['nama_mk'];
    }
}

// Get top performers statistics
$top_performer = !empty($leaderboard) ? $leaderboard[0] : null;
$total_students = count($leaderboard);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Leaderboard - Sistem Tugas Mahasiswa</title>
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
        .rank-badge {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            color: white;
        }
        .rank-1 { background: linear-gradient(135deg, #ffd700, #ffed4e); color: #333; }
        .rank-2 { background: linear-gradient(135deg, #c0c0c0, #e5e5e5); color: #333; }
        .rank-3 { background: linear-gradient(135deg, #cd7f32, #daa520); color: white; }
        .rank-other { background: linear-gradient(135deg, #6c757d, #adb5bd); }
        
        .student-card {
            transition: transform 0.3s ease;
            border-left: 4px solid transparent;
        }
        .student-card:hover {
            transform: translateY(-2px);
        }
        .student-card.current-user {
            border-left-color: #007bff;
            background-color: #f8f9ff;
        }
        .trophy-icon {
            font-size: 1.5rem;
            margin-right: 10px;
        }
        .trophy-gold { color: #ffd700; }
        .trophy-silver { color: #c0c0c0; }
        .trophy-bronze { color: #cd7f32; }
        
        .stats-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }
        .progress-custom {
            height: 8px;
            border-radius: 4px;
        }
        .filter-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            padding: 1rem;
            margin-bottom: 1.5rem;
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
                        <a class="nav-link" href="mata-kuliah.php">
                            <i class="fas fa-book"></i> Mata Kuliah Saya
                        </a>
                        <a class="nav-link" href="tugas-saya.php">
                            <i class="fas fa-tasks"></i> Tugas Saya
                        </a>
                        <a class="nav-link" href="nilai-saya.php">
                            <i class="fas fa-star"></i> Nilai Saya
                        </a>
                        <a class="nav-link active" href="leaderboard.php">
                            <i class="fas fa-trophy"></i> Leaderboard
                        </a>
                        <a class="nav-link" href="notifikasi.php">
                            <i class="fas fa-bell"></i> Notifikasi
                        </a>
                    </div>
                </div>
            </div>

            <!-- Main Content -->
            <div class="col-md-9 col-lg-10">
                <!-- Page Header -->
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h2><i class="fas fa-trophy text-warning"></i> Leaderboard</h2>
                        <p class="text-muted mb-0">Peringkat mahasiswa berdasarkan performa tugas</p>
                    </div>
                </div>

                <!-- Filter Card -->
                <div class="filter-card">
                    <form method="GET" class="row g-3 align-items-center">
                        <div class="col-md-8">
                            <label for="mk" class="form-label">Filter Mata Kuliah:</label>
                            <select name="mk" id="mk" class="form-select" onchange="this.form.submit()">
                                <option value="0">Semua Mata Kuliah</option>
                                <?php foreach ($mata_kuliah as $mk): ?>
                                    <option value="<?= $mk['id'] ?>" <?= $selected_mk == $mk['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($mk['kode_mk'] . ' - ' . $mk['nama_mk']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4 text-end">
                            <small class="text-muted">
                                <i class="fas fa-users"></i> <?= $total_students ?> mahasiswa terdaftar
                            </small>
                        </div>
                    </form>
                </div>

                <?php if ($current_user_data): ?>
                <!-- Current User Stats -->
                <div class="stats-card">
                    <div class="row align-items-center">
                        <div class="col-md-8">
                            <h4><i class="fas fa-user"></i> Peringkat Anda</h4>
                            <div class="row">
                                <div class="col-md-6">
                                    <h2 class="mb-0"># <?= $current_user_rank ?></h2>
                                    <p class="mb-0">dari <?= $total_students ?> mahasiswa</p>
                                </div>
                                <div class="col-md-6">
                                    <h3 class="mb-0"><?= number_format($current_user_data['rata_rata_nilai'], 1) ?></h3>
                                    <p class="mb-0">Rata-rata nilai</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4 text-end">
                            <div class="row text-center">
                                <div class="col-4">
                                    <h5><?= $current_user_data['total_tugas_dinilai'] ?></h5>
                                    <small>Dinilai</small>
                                </div>
                                <div class="col-4">
                                    <h5><?= $current_user_data['tugas_tepat_waktu'] ?></h5>
                                    <small>Tepat Waktu</small>
                                </div>
                                <div class="col-4">
                                    <h5><?= $current_user_data['tugas_terlambat'] ?></h5>
                                    <small>Terlambat</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Leaderboard -->
                <div class="card">
                    <div class="card-header">
                        <div class="d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">
                                <i class="fas fa-ranking-star"></i> 
                                Peringkat - <?= htmlspecialchars($mk_name) ?>
                            </h5>
                            <div class="d-flex gap-3">
                                <small><i class="fas fa-trophy trophy-gold"></i> Emas</small>
                                <small><i class="fas fa-trophy trophy-silver"></i> Silver</small>
                                <small><i class="fas fa-trophy trophy-bronze"></i> Perunggu</small>
                            </div>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($leaderboard)): ?>
                            <div class="text-center py-5">
                                <i class="fas fa-chart-line fa-3x text-muted mb-3"></i>
                                <h5>Belum Ada Data Peringkat</h5>
                                <p class="text-muted">Peringkat akan muncul setelah ada tugas yang dinilai.</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($leaderboard as $index => $student): ?>
                                <?php 
                                $rank = $index + 1;
                                $isCurrentUser = $student['nim'] == $user['nim'];
                                $rankClass = '';
                                $trophyClass = '';
                                
                                if ($rank == 1) {
                                    $rankClass = 'rank-1';
                                    $trophyClass = 'trophy-gold';
                                } elseif ($rank == 2) {
                                    $rankClass = 'rank-2';
                                    $trophyClass = 'trophy-silver';
                                } elseif ($rank == 3) {
                                    $rankClass = 'rank-3';
                                    $trophyClass = 'trophy-bronze';
                                } else {
                                    $rankClass = 'rank-other';
                                }
                                
                                $completionRate = $student['total_tugas_dikumpulkan'] > 0 ? 
                                    ($student['total_tugas_dinilai'] / $student['total_tugas_dikumpulkan']) * 100 : 0;
                                ?>
                                <div class="student-card p-3 border-bottom <?= $isCurrentUser ? 'current-user' : '' ?>">
                                    <div class="row align-items-center">
                                        <div class="col-md-1 text-center">
                                            <?php if ($rank <= 3): ?>
                                                <i class="fas fa-trophy trophy-icon <?= $trophyClass ?>"></i>
                                            <?php endif; ?>
                                            <div class="rank-badge <?= $rankClass ?>">
                                                <?= $rank ?>
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <h6 class="mb-1 <?= $isCurrentUser ? 'text-primary fw-bold' : '' ?>">
                                                <?= htmlspecialchars($student['full_name']) ?>
                                                <?php if ($isCurrentUser): ?>
                                                    <span class="badge bg-primary ms-1">Anda</span>
                                                <?php endif; ?>
                                            </h6>
                                            <small class="text-muted"><?= htmlspecialchars($student['nim']) ?></small>
                                        </div>
                                        <div class="col-md-2 text-center">
                                            <h5 class="mb-0 text-primary">
                                                <?= number_format($student['rata_rata_nilai'], 1) ?>
                                            </h5>
                                            <small class="text-muted">Rata-rata</small>
                                        </div>
                                        <div class="col-md-2 text-center">
                                            <h6 class="mb-0"><?= $student['total_tugas_dinilai'] ?></h6>
                                            <small class="text-muted">Tugas Dinilai</small>
                                        </div>
                                        <div class="col-md-2 text-center">
                                            <h6 class="mb-0 text-success"><?= $student['tugas_tepat_waktu'] ?></h6>
                                            <small class="text-muted">Tepat Waktu</small>
                                        </div>
                                        <div class="col-md-2">
                                            <div class="d-flex justify-content-between align-items-center mb-1">
                                                <small class="text-muted">Completion</small>
                                                <small class="fw-bold"><?= number_format($completionRate, 0) ?>%</small>
                                            </div>
                                            <div class="progress progress-custom">
                                                <div class="progress-bar bg-success" 
                                                     style="width: <?= $completionRate ?>%"></div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if (!empty($leaderboard) && $top_performer): ?>
                <!-- Top Performer Highlight -->
                <div class="row mt-4">
                    <div class="col-md-6">
                        <div class="card border-warning">
                            <div class="card-header bg-warning">
                                <h6 class="mb-0 text-dark">
                                    <i class="fas fa-crown"></i> Top Performer
                                </h6>
                            </div>
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <i class="fas fa-trophy trophy-gold fa-2x me-3"></i>
                                    <div>
                                        <h5 class="mb-1"><?= htmlspecialchars($top_performer['full_name']) ?></h5>
                                        <p class="text-muted mb-1"><?= htmlspecialchars($top_performer['nim']) ?></p>
                                        <h4 class="text-warning mb-0">
                                            <?= number_format($top_performer['rata_rata_nilai'], 1) ?> 
                                            <small class="text-muted">/ 100</small>
                                        </h4>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card border-info">
                            <div class="card-header bg-info text-white">
                                <h6 class="mb-0">
                                    <i class="fas fa-chart-bar"></i> Statistik Keseluruhan
                                </h6>
                            </div>
                            <div class="card-body">
                                <?php
                                $avg_score = array_sum(array_column($leaderboard, 'rata_rata_nilai')) / count($leaderboard);
                                $total_assignments_graded = array_sum(array_column($leaderboard, 'total_tugas_dinilai'));
                                $total_on_time = array_sum(array_column($leaderboard, 'tugas_tepat_waktu'));
                                ?>
                                <div class="row text-center">
                                    <div class="col-4">
                                        <h5 class="text-info"><?= number_format($avg_score, 1) ?></h5>
                                        <small class="text-muted">Rata-rata Kelas</small>
                                    </div>
                                    <div class="col-4">
                                        <h5 class="text-info"><?= $total_assignments_graded ?></h5>
                                        <small class="text-muted">Total Dinilai</small>
                                    </div>
                                    <div class="col-4">
                                        <h5 class="text-info"><?= $total_on_time ?></h5>
                                        <small class="text-muted">Tepat Waktu</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
    <script>
        // Smooth scroll ke posisi user jika ada
        document.addEventListener('DOMContentLoaded', function() {
            const currentUserCard = document.querySelector('.current-user');
            if (currentUserCard && window.location.hash !== '#top') {
                setTimeout(() => {
                    currentUserCard.scrollIntoView({ 
                        behavior: 'smooth', 
                        block: 'center' 
                    });
                }, 500);
            }
        });

        // Auto refresh setiap 2 menit
        setTimeout(function() {
            location.reload();
        }, 120000);
    </script>
</body>
</html>
