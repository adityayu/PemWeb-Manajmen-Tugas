<?php
require_once 'includes/auth.php';
requireLogin();

// Redirect jika bukan mahasiswa
if (isAdmin()) {
    header('Location: dashboard.php');
    exit;
}

$user = getCurrentUser();

// Filter parameters
$filter_mata_kuliah = $_GET['mata_kuliah'] ?? '';
$filter_semester = $_GET['semester'] ?? '';
$search = $_GET['search'] ?? '';

// Base query untuk mengambil nilai mahasiswa
$base_query = "
    SELECT pt.*, t.judul as tugas_judul, t.bobot, t.deadline,
           mk.nama_mk, mk.kode_mk, mk.semester, mk.sks,
           u_dosen.full_name as dosen_nama
    FROM pengumpulan_tugas pt
    JOIN tugas t ON pt.tugas_id = t.id
    JOIN mata_kuliah mk ON t.mata_kuliah_id = mk.id
    JOIN users u_dosen ON mk.dosen_id = u_dosen.id
    WHERE pt.mahasiswa_id = ? AND pt.nilai IS NOT NULL
";

$params = [$user['id']];
$where_conditions = [];

// Filter berdasarkan mata kuliah
if (!empty($filter_mata_kuliah)) {
    $where_conditions[] = "mk.id = ?";
    $params[] = $filter_mata_kuliah;
}

// Filter berdasarkan semester
if (!empty($filter_semester)) {
    $where_conditions[] = "mk.semester = ?";
    $params[] = $filter_semester;
}

// Search berdasarkan judul tugas atau nama mata kuliah
if (!empty($search)) {
    $where_conditions[] = "(t.judul LIKE ? OR mk.nama_mk LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

// Gabungkan kondisi WHERE
if (!empty($where_conditions)) {
    $base_query .= " AND " . implode(" AND ", $where_conditions);
}

$base_query .= " ORDER BY pt.graded_at DESC";

// Ambil data nilai
$grades = $db->select($base_query, $params);

// Ambil daftar mata kuliah untuk filter
$mata_kuliah_list = $db->select("
    SELECT DISTINCT mk.id, mk.nama_mk, mk.kode_mk, mk.semester
    FROM mata_kuliah mk
    JOIN enrollments e ON mk.id = e.mata_kuliah_id
    WHERE e.mahasiswa_id = ?
    ORDER BY mk.semester DESC, mk.nama_mk ASC
", [$user['id']]);

// Ambil daftar semester untuk filter
$semester_list = $db->select("
    SELECT DISTINCT mk.semester
    FROM mata_kuliah mk
    JOIN enrollments e ON mk.id = e.mata_kuliah_id
    WHERE e.mahasiswa_id = ?
    ORDER BY mk.semester DESC
", [$user['id']]);

// Hitung statistik
$stats = [
    'total_dinilai' => count($grades),
    'rata_rata' => count($grades) > 0 ? array_sum(array_column($grades, 'nilai')) / count($grades) : 0,
    'nilai_tertinggi' => count($grades) > 0 ? max(array_column($grades, 'nilai')) : 0,
    'nilai_terendah' => count($grades) > 0 ? min(array_column($grades, 'nilai')) : 0
];

// Fungsi untuk mendapatkan grade letter
function getGradeLetter($nilai) {
    if ($nilai >= 85) return 'A';
    if ($nilai >= 80) return 'A-';
    if ($nilai >= 75) return 'B+';
    if ($nilai >= 70) return 'B';
    if ($nilai >= 65) return 'B-';
    if ($nilai >= 60) return 'C+';
    if ($nilai >= 55) return 'C';
    if ($nilai >= 50) return 'C-';
    if ($nilai >= 45) return 'D+';
    if ($nilai >= 40) return 'D';
    return 'E';
}

// Fungsi untuk mendapatkan warna badge berdasarkan nilai
function getGradeColor($nilai) {
    if ($nilai >= 80) return 'success';
    if ($nilai >= 70) return 'primary';
    if ($nilai >= 60) return 'warning';
    return 'danger';
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nilai Saya - Sistem Tugas Mahasiswa</title>
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
        .grade-item {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            transition: transform 0.2s ease;
            border-left: 4px solid #007bff;
        }
        .grade-item:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
        }
        .grade-badge {
            font-size: 1.2em;
            font-weight: bold;
            min-width: 60px;
        }
        .grade-letter {
            font-size: 1.5em;
            font-weight: bold;
        }
        .filter-card {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        .empty-state {
            text-align: center;
            padding: 3rem 1rem;
            color: #6c757d;
        }
        .grade-detail-modal .modal-content {
            border-radius: 15px;
            border: none;
        }
        .grade-detail-modal .modal-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px 15px 0 0;
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
                        <a class="nav-link active" href="nilai-saya.php">
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
                        <h2><i class="fas fa-star"></i> Nilai Saya</h2>
                        <p class="text-muted mb-0">Lihat semua nilai dan feedback tugas Anda</p>
                    </div>
                </div>

                <!-- Statistics Cards -->
                <div class="row mb-4">
                    <div class="col-md-3 mb-3">
                        <div class="stat-card primary p-3">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <h6 class="text-muted">Total Dinilai</h6>
                                    <h3 class="mb-0"><?= $stats['total_dinilai'] ?></h3>
                                </div>
                                <i class="fas fa-star fa-2x text-primary"></i>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <div class="stat-card success p-3">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <h6 class="text-muted">Rata-rata</h6>
                                    <h3 class="mb-0"><?= number_format($stats['rata_rata'], 1) ?></h3>
                                </div>
                                <i class="fas fa-chart-line fa-2x text-success"></i>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <div class="stat-card warning p-3">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <h6 class="text-muted">Tertinggi</h6>
                                    <h3 class="mb-0"><?= number_format($stats['nilai_tertinggi'], 1) ?></h3>
                                </div>
                                <i class="fas fa-arrow-up fa-2x text-warning"></i>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <div class="stat-card danger p-3">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <h6 class="text-muted">Terendah</h6>
                                    <h3 class="mb-0"><?= $stats['total_dinilai'] > 0 ? number_format($stats['nilai_terendah'], 1) : '0' ?></h3>
                                </div>
                                <i class="fas fa-arrow-down fa-2x text-danger"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filter Card -->
                <div class="filter-card p-3 mb-4">
                    <form method="GET" class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Mata Kuliah</label>
                            <select name="mata_kuliah" class="form-select">
                                <option value="">Semua Mata Kuliah</option>
                                <?php foreach ($mata_kuliah_list as $mk): ?>
                                    <option value="<?= $mk['id'] ?>" <?= $filter_mata_kuliah == $mk['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($mk['kode_mk'] . ' - ' . $mk['nama_mk']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Semester</label>
                            <select name="semester" class="form-select">
                                <option value="">Semua Semester</option>
                                <?php foreach ($semester_list as $sem): ?>
                                    <option value="<?= $sem['semester'] ?>" <?= $filter_semester == $sem['semester'] ? 'selected' : '' ?>>
                                        Semester <?= $sem['semester'] ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Cari Tugas</label>
                            <input type="text" name="search" class="form-control" placeholder="Nama tugas..." value="<?= htmlspecialchars($search) ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">&nbsp;</label>
                            <div class="d-grid">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-search"></i> Filter
                                </button>
                            </div>
                        </div>
                    </form>
                    <?php if (!empty($filter_mata_kuliah) || !empty($filter_semester) || !empty($search)): ?>
                        <div class="mt-2">
                            <a href="nilai-saya.php" class="btn btn-outline-secondary btn-sm">
                                <i class="fas fa-times"></i> Reset Filter
                            </a>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Grades List -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-list"></i> Daftar Nilai (<?= count($grades) ?> tugas)</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($grades)): ?>
                            <div class="empty-state">
                                <i class="fas fa-star fa-4x mb-3"></i>
                                <h4>Belum ada nilai</h4>
                                <p>Nilai akan muncul setelah dosen menilai tugas yang Anda kumpulkan.</p>
                                <a href="tugas-saya.php" class="btn btn-primary">
                                    <i class="fas fa-tasks"></i> Lihat Tugas Saya
                                </a>
                            </div>
                        <?php else: ?>
                            <div class="row">
                                <?php foreach ($grades as $grade): ?>
                                    <div class="col-12 mb-3">
                                        <div class="grade-item p-3">
                                            <div class="row align-items-center">
                                                <div class="col-md-6">
                                                    <h6 class="mb-1 fw-bold"><?= htmlspecialchars($grade['tugas_judul']) ?></h6>
                                                    <p class="text-muted mb-1">
                                                        <i class="fas fa-book"></i> <?= htmlspecialchars($grade['kode_mk'] . ' - ' . $grade['nama_mk']) ?>
                                                    </p>
                                                    <small class="text-muted">
                                                        <i class="fas fa-user"></i> <?= htmlspecialchars($grade['dosen_nama']) ?> |
                                                        <i class="fas fa-calendar"></i> Dinilai: <?= date('d/m/Y H:i', strtotime($grade['graded_at'])) ?>
                                                    </small>
                                                </div>
                                                <div class="col-md-3 text-center">
                                                    <div class="d-flex align-items-center justify-content-center">
                                                        <span class="grade-badge badge bg-<?= getGradeColor($grade['nilai']) ?> me-2">
                                                            <?= number_format($grade['nilai'], 1) ?>
                                                        </span>
                                                        <span class="grade-letter text-<?= getGradeColor($grade['nilai']) ?>">
                                                            <?= getGradeLetter($grade['nilai']) ?>
                                                        </span>
                                                    </div>
                                                    <?php if ($grade['bobot'] > 0): ?>
                                                        <small class="text-muted">Bobot: <?= $grade['bobot'] ?>%</small>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="col-md-3 text-end">
                                                    <div class="mb-2">
                                                        <?php if ($grade['is_late']): ?>
                                                            <span class="badge bg-danger mb-1">Terlambat</span><br>
                                                        <?php endif; ?>
                                                        <small class="text-muted">
                                                            Deadline: <?= date('d/m/Y H:i', strtotime($grade['deadline'])) ?>
                                                        </small>
                                                    </div>
                                                    <button class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#gradeModal<?= $grade['id'] ?>">
                                                        <i class="fas fa-eye"></i> Detail
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Grade Detail Modal -->
                                    <div class="modal fade grade-detail-modal" id="gradeModal<?= $grade['id'] ?>" tabindex="-1">
                                        <div class="modal-dialog modal-lg">
                                            <div class="modal-content">
                                                <div class="modal-header">
                                                    <h5 class="modal-title">Detail Nilai - <?= htmlspecialchars($grade['tugas_judul']) ?></h5>
                                                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                                </div>
                                                <div class="modal-body">
                                                    <div class="row">
                                                        <div class="col-md-6">
                                                            <h6>Informasi Tugas</h6>
                                                            <table class="table table-borderless">
                                                                <tr>
                                                                    <td><strong>Mata Kuliah:</strong></td>
                                                                    <td><?= htmlspecialchars($grade['nama_mk']) ?></td>
                                                                </tr>
                                                                <tr>
                                                                    <td><strong>Dosen:</strong></td>
                                                                    <td><?= htmlspecialchars($grade['dosen_nama']) ?></td>
                                                                </tr>
                                                                <tr>
                                                                    <td><strong>Deadline:</strong></td>
                                                                    <td><?= date('d F Y, H:i', strtotime($grade['deadline'])) ?></td>
                                                                </tr>
                                                                <tr>
                                                                    <td><strong>Dikumpulkan:</strong></td>
                                                                    <td>
                                                                        <?= date('d F Y, H:i', strtotime($grade['submitted_at'])) ?>
                                                                        <?php if ($grade['is_late']): ?>
                                                                            <span class="badge bg-danger ms-2">Terlambat</span>
                                                                        <?php else: ?>
                                                                            <span class="badge bg-success ms-2">Tepat Waktu</span>
                                                                        <?php endif; ?>
                                                                    </td>
                                                                </tr>
                                                            </table>
                                                        </div>
                                                        <div class="col-md-6">
                                                            <h6>Penilaian</h6>
                                                            <div class="text-center mb-3">
                                                                <div class="display-4 fw-bold text-<?= getGradeColor($grade['nilai']) ?>">
                                                                    <?= number_format($grade['nilai'], 1) ?>
                                                                </div>
                                                                <div class="h4 text-<?= getGradeColor($grade['nilai']) ?>">
                                                                    <?= getGradeLetter($grade['nilai']) ?>
                                                                </div>
                                                                <?php if ($grade['bobot'] > 0): ?>
                                                                    <small class="text-muted">Bobot: <?= $grade['bobot'] ?>%</small>
                                                                <?php endif; ?>
                                                            </div>
                                                            <p><strong>Dinilai pada:</strong><br>
                                                            <?= date('d F Y, H:i', strtotime($grade['graded_at'])) ?></p>
                                                        </div>
                                                    </div>
                                                    
                                                    <?php if (!empty($grade['feedback'])): ?>
                                                        <hr>
                                                        <h6><i class="fas fa-comment"></i> Feedback Dosen</h6>
                                                        <div class="alert alert-info">
                                                            <?= nl2br(htmlspecialchars($grade['feedback'])) ?>
                                                        </div>
                                                    <?php endif; ?>

                                                    <?php if (!empty($grade['file_path'])): ?>
                                                        <hr>
                                                        <h6><i class="fas fa-file"></i> File yang Dikumpulkan</h6>
                                                        <a href="<?= htmlspecialchars($grade['file_path']) ?>" class="btn btn-outline-primary" target="_blank">
                                                            <i class="fas fa-download"></i> Download File
                                                        </a>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
    <script>
        // Highlight navigation
        document.addEventListener('DOMContentLoaded', function() {
            // Auto-focus search input when page loads
            const searchInput = document.querySelector('input[name="search"]');
            if (searchInput && searchInput.value === '') {
                // Only focus if there's no existing search value
                setTimeout(() => searchInput.focus(), 100);
            }
        });

        // Auto-submit form on select change
        document.querySelectorAll('select[name="mata_kuliah"], select[name="semester"]').forEach(select => {
            select.addEventListener('change', function() {
                this.form.submit();
            });
        });
    </script>
</body>
</html>
