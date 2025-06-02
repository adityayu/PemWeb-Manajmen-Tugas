<?php
require_once 'includes/auth.php';
requireLogin();

// Pastikan hanya mahasiswa yang bisa akses
if (isAdmin()) {
    header('Location: dashboard.php');
    exit;
}

$user = getCurrentUser();

// Get filter parameters
$status_filter = $_GET['status'] ?? 'all';
$mata_kuliah_filter = $_GET['mata_kuliah'] ?? 'all';
$search = $_GET['search'] ?? '';

// Build WHERE clause berdasarkan filter
$where_conditions = ["e.mahasiswa_id = ?"];
$params = [$user['id']];

if ($mata_kuliah_filter !== 'all') {
    $where_conditions[] = "mk.id = ?";
    $params[] = $mata_kuliah_filter;
}

if (!empty($search)) {
    $where_conditions[] = "(t.judul LIKE ? OR t.deskripsi LIKE ? OR mk.nama_mk LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
}

$where_clause = implode(' AND ', $where_conditions);

// Query untuk mendapatkan semua tugas
$base_query = "
    SELECT 
        t.*,
        mk.nama_mk,
        mk.kode_mk,
        pt.id as pengumpulan_id,
        pt.file_path,
        pt.submitted_at,
        pt.nilai,
        pt.feedback,
        pt.graded_at,
        pt.is_late,
        CASE 
            WHEN pt.id IS NOT NULL THEN 'submitted'
            WHEN t.deadline < NOW() THEN 'overdue'
            ELSE 'pending'
        END as status,
        CASE 
            WHEN pt.nilai IS NOT NULL THEN 'graded'
            WHEN pt.id IS NOT NULL THEN 'submitted'
            WHEN t.deadline < NOW() THEN 'overdue'
            ELSE 'pending'
        END as detailed_status
    FROM tugas t
    JOIN mata_kuliah mk ON t.mata_kuliah_id = mk.id
    JOIN enrollments e ON mk.id = e.mata_kuliah_id
    LEFT JOIN pengumpulan_tugas pt ON (t.id = pt.tugas_id AND pt.mahasiswa_id = ?)
    WHERE t.is_active = 1 AND $where_clause
";

// Add status filter
if ($status_filter !== 'all') {
    switch ($status_filter) {
        case 'pending':
            $base_query .= " HAVING detailed_status = 'pending'";
            break;
        case 'submitted':
            $base_query .= " HAVING detailed_status IN ('submitted', 'graded')";
            break;
        case 'overdue':
            $base_query .= " HAVING detailed_status = 'overdue'";
            break;
        case 'graded':
            $base_query .= " HAVING detailed_status = 'graded'";
            break;
    }
}

$base_query .= " ORDER BY t.deadline ASC";

// Add user ID at the beginning of params for LEFT JOIN
array_unshift($params, $user['id']);

$tugas_list = $db->select($base_query, $params);

// Get mata kuliah untuk filter dropdown
$mata_kuliah_list = $db->select("
    SELECT DISTINCT mk.id, mk.nama_mk, mk.kode_mk
    FROM mata_kuliah mk
    JOIN enrollments e ON mk.id = e.mata_kuliah_id
    WHERE e.mahasiswa_id = ?
    ORDER BY mk.nama_mk ASC
", [$user['id']]);

// Statistics
$stats = [
    'total' => count($tugas_list),
    'pending' => count(array_filter($tugas_list, fn($t) => $t['detailed_status'] === 'pending')),
    'submitted' => count(array_filter($tugas_list, fn($t) => in_array($t['detailed_status'], ['submitted', 'graded']))),
    'overdue' => count(array_filter($tugas_list, fn($t) => $t['detailed_status'] === 'overdue')),
    'graded' => count(array_filter($tugas_list, fn($t) => $t['detailed_status'] === 'graded'))
];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tugas Saya - Sistem Tugas Mahasiswa</title>
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
            margin-bottom: 1rem;
        }
        .card-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px 15px 0 0 !important;
        }
        .task-card {
            border-left: 5px solid;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .task-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.12);
        }
        .task-card.pending { border-left-color: #ffc107; }
        .task-card.submitted { border-left-color: #20c997; }
        .task-card.overdue { border-left-color: #dc3545; }
        .task-card.graded { border-left-color: #0d6efd; }
        .status-badge {
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.875rem;
            font-weight: 600;
        }
        .deadline-info {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 1rem;
            margin: 0.5rem 0;
        }
        .filter-section {
            background: white;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            padding: 1.5rem;
            margin-bottom: 2rem;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }
        .stat-item {
            background: white;
            padding: 1.5rem;
            border-radius: 10px;
            text-align: center;
            box-shadow: 0 3px 10px rgba(0,0,0,0.08);
        }
        .grade-display {
            font-size: 1.5rem;
            font-weight: bold;
            color: #0d6efd;
        }
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: #666;
        }
        .empty-state i {
            font-size: 4rem;
            margin-bottom: 1rem;
            opacity: 0.5;
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
                        <a class="nav-link" href="dashboard.php">
                            <i class="fas fa-tachometer-alt"></i> Dashboard
                        </a>
                        <a class="nav-link" href="mata-kuliah.php">
                            <i class="fas fa-book"></i> Mata Kuliah Saya
                        </a>
                        <a class="nav-link active" href="tugas-saya.php">
                            <i class="fas fa-tasks"></i> Tugas Saya
                        </a>
                        <a class="nav-link" href="nilai-saya.php">
                            <i class="fas fa-star"></i> Nilai Saya
                        </a>
                        <a class="nav-link" href="leaderboard.php">
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
                        <h2><i class="fas fa-tasks"></i> Tugas Saya</h2>
                        <p class="text-muted mb-0">Kelola dan pantau semua tugas kuliah Anda</p>
                    </div>
                </div>

                <!-- Statistics -->
                <div class="stats-grid">
                    <div class="stat-item">
                        <i class="fas fa-clipboard-list fa-2x text-primary mb-2"></i>
                        <h4><?= $stats['total'] ?></h4>
                        <p class="mb-0 text-muted">Total Tugas</p>
                    </div>
                    <div class="stat-item">
                        <i class="fas fa-clock fa-2x text-warning mb-2"></i>
                        <h4><?= $stats['pending'] ?></h4>
                        <p class="mb-0 text-muted">Belum Dikumpulkan</p>
                    </div>
                    <div class="stat-item">
                        <i class="fas fa-upload fa-2x text-success mb-2"></i>
                        <h4><?= $stats['submitted'] ?></h4>
                        <p class="mb-0 text-muted">Sudah Dikumpulkan</p>
                    </div>
                    <div class="stat-item">
                        <i class="fas fa-star fa-2x text-info mb-2"></i>
                        <h4><?= $stats['graded'] ?></h4>
                        <p class="mb-0 text-muted">Sudah Dinilai</p>
                    </div>
                </div>

                <!-- Filter Section -->
                <div class="filter-section">
                    <form method="GET" class="row g-3 align-items-end">
                        <div class="col-md-3">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-select">
                                <option value="all" <?= $status_filter === 'all' ? 'selected' : '' ?>>Semua Status</option>
                                <option value="pending" <?= $status_filter === 'pending' ? 'selected' : '' ?>>Belum Dikumpulkan</option>
                                <option value="submitted" <?= $status_filter === 'submitted' ? 'selected' : '' ?>>Sudah Dikumpulkan</option>
                                <option value="overdue" <?= $status_filter === 'overdue' ? 'selected' : '' ?>>Terlambat</option>
                                <option value="graded" <?= $status_filter === 'graded' ? 'selected' : '' ?>>Sudah Dinilai</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Mata Kuliah</label>
                            <select name="mata_kuliah" class="form-select">
                                <option value="all">Semua Mata Kuliah</option>
                                <?php foreach ($mata_kuliah_list as $mk): ?>
                                    <option value="<?= $mk['id'] ?>" <?= $mata_kuliah_filter == $mk['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($mk['kode_mk']) ?> - <?= htmlspecialchars($mk['nama_mk']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Pencarian</label>
                            <input type="text" name="search" class="form-control" placeholder="Cari judul tugas..." value="<?= htmlspecialchars($search) ?>">
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="fas fa-search"></i> Filter
                            </button>
                        </div>
                    </form>
                    <?php if ($status_filter !== 'all' || $mata_kuliah_filter !== 'all' || !empty($search)): ?>
                        <div class="mt-3">
                            <a href="tugas-saya.php" class="btn btn-outline-secondary">
                                <i class="fas fa-times"></i> Reset Filter
                            </a>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Task List -->
                <div class="row">
                    <?php if (empty($tugas_list)): ?>
                        <div class="col-12">
                            <div class="card">
                                <div class="card-body">
                                    <div class="empty-state">
                                        <i class="fas fa-tasks"></i>
                                        <h4>Tidak ada tugas ditemukan</h4>
                                        <p>Tidak ada tugas yang sesuai dengan filter yang dipilih.</p>
                                        <a href="tugas-saya.php" class="btn btn-primary">
                                            <i class="fas fa-refresh"></i> Lihat Semua Tugas
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
                        <?php foreach ($tugas_list as $tugas): ?>
                            <div class="col-12 mb-3">
                                <div class="card task-card <?= $tugas['detailed_status'] ?>">
                                    <div class="card-body">
                                        <div class="row align-items-center">
                                            <div class="col-md-8">
                                                <div class="d-flex justify-content-between align-items-start mb-2">
                                                    <h5 class="card-title mb-0"><?= htmlspecialchars($tugas['judul']) ?></h5>
                                                    <?php
                                                    $status_class = [
                                                        'pending' => 'bg-warning text-dark',
                                                        'submitted' => 'bg-success',
                                                        'overdue' => 'bg-danger',
                                                        'graded' => 'bg-primary'
                                                    ];
                                                    $status_text = [
                                                        'pending' => 'Belum Dikumpulkan',
                                                        'submitted' => 'Sudah Dikumpulkan',
                                                        'overdue' => 'Terlambat',
                                                        'graded' => 'Sudah Dinilai'
                                                    ];
                                                    ?>
                                                    <span class="badge <?= $status_class[$tugas['detailed_status']] ?> status-badge">
                                                        <?= $status_text[$tugas['detailed_status']] ?>
                                                    </span>
                                                </div>
                                                
                                                <p class="text-muted mb-2">
                                                    <i class="fas fa-book"></i> <?= htmlspecialchars($tugas['kode_mk']) ?> - <?= htmlspecialchars($tugas['nama_mk']) ?>
                                                </p>
                                                
                                                <p class="card-text"><?= htmlspecialchars(substr($tugas['deskripsi'], 0, 150)) ?>...</p>
                                                
                                                <div class="deadline-info">
                                                    <div class="row">
                                                        <div class="col-md-6">
                                                            <small class="text-muted">
                                                                <i class="fas fa-calendar-alt"></i> 
                                                                Deadline: <?= date('d/m/Y H:i', strtotime($tugas['deadline'])) ?>
                                                            </small>
                                                        </div>
                                                        <?php if ($tugas['submitted_at']): ?>
                                                            <div class="col-md-6">
                                                                <small class="text-muted">
                                                                    <i class="fas fa-upload"></i> 
                                                                    Dikumpulkan: <?= date('d/m/Y H:i', strtotime($tugas['submitted_at'])) ?>
                                                                    <?php if ($tugas['is_late']): ?>
                                                                        <span class="text-danger">(Terlambat)</span>
                                                                    <?php endif; ?>
                                                                </small>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <div class="col-md-4 text-end">
                                                <?php if ($tugas['detailed_status'] === 'graded'): ?>
                                                    <div class="mb-3">
                                                        <div class="grade-display"><?= number_format($tugas['nilai'], 1) ?></div>
                                                        <small class="text-muted">Nilai</small>
                                                        <?php if ($tugas['feedback']): ?>
                                                            <div class="mt-2">
                                                                <small class="text-muted">
                                                                    <i class="fas fa-comment"></i> Ada feedback
                                                                </small>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endif; ?>
                                                
                                                <div class="d-flex flex-column gap-2">
                                                    <a href="tugas-detail.php?id=<?= $tugas['id'] ?>" class="btn btn-outline-primary btn-sm">
                                                        <i class="fas fa-eye"></i> Lihat Detail
                                                    </a>
                                                    
                                                    <?php if ($tugas['detailed_status'] === 'pending' && $tugas['deadline'] > date('Y-m-d H:i:s')): ?>
                                                        <a href="tugas-submit.php?id=<?= $tugas['id'] ?>" class="btn btn-success btn-sm">
                                                            <i class="fas fa-upload"></i> Kumpulkan
                                                        </a>
                                                    <?php elseif ($tugas['pengumpulan_id']): ?>
                                                        <a href="tugas-detail.php?id=<?= $tugas['id'] ?>" class="btn btn-info btn-sm">
                                                            <i class="fas fa-file"></i> Lihat Pengumpulan
                                                        </a>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
    <script>
        // Auto refresh halaman setiap 5 menit untuk update status
        setTimeout(function() {
            location.reload();
        }, 300000);

        // Highlight deadline yang dekat
        document.addEventListener('DOMContentLoaded', function() {
            const deadlines = document.querySelectorAll('.deadline-info');
            const now = new Date();
            
            deadlines.forEach(function(deadline) {
                const deadlineText = deadline.querySelector('small').textContent;
                const deadlineMatch = deadlineText.match(/(\d{2}\/\d{2}\/\d{4} \d{2}:\d{2})/);
                
                if (deadlineMatch) {
                    const deadlineDate = new Date(deadlineMatch[1].replace(/(\d{2})\/(\d{2})\/(\d{4})/, '$3-$2-$1'));
                    const timeDiff = deadlineDate - now;
                    const hoursDiff = timeDiff / (1000 * 3600);
                    
                    if (hoursDiff <= 24 && hoursDiff > 0) {
                        deadline.classList.add('bg-warning');
                        deadline.classList.add('text-dark');
                    }
                }
            });
        });

        // Filter form auto-submit on change
        const filterSelects = document.querySelectorAll('select[name="status"], select[name="mata_kuliah"]');
        filterSelects.forEach(select => {
            select.addEventListener('change', function() {
                this.form.submit();
            });
        });
    </script>
</body>
</html>
