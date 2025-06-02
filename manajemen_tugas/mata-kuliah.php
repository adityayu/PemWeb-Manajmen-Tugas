<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'includes/auth.php';
requireLogin();

$user = getCurrentUser();
$success_message = '';
$error_message = '';

// Handle form submissions untuk admin
if (isAdmin() && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'create':
                $kode_mk = trim($_POST['kode_mk']);
                $nama_mk = trim($_POST['nama_mk']);
                $sks = (int)$_POST['sks'];
                $semester = trim($_POST['semester']);
                $tahun_ajaran = trim($_POST['tahun_ajaran']);

                // Validation
                if (empty($kode_mk) || empty($nama_mk) || $sks < 1 || $sks > 6 || empty($semester) || empty($tahun_ajaran)) {
                    $error_message = 'Semua field harus diisi dengan benar!';
                } else {
                    // Check if kode_mk already exists
                    $existing = $db->selectOne("SELECT id FROM mata_kuliah WHERE kode_mk = ?", [$kode_mk]);
                    if ($existing) {
                        $error_message = 'Kode mata kuliah sudah ada!';
                    } else {
                        $db->query("INSERT INTO mata_kuliah (kode_mk, nama_mk, sks, dosen_id, semester, tahun_ajaran) VALUES (?, ?, ?, ?, ?, ?)", 
                            [$kode_mk, $nama_mk, $sks, $user['id'], $semester, $tahun_ajaran]);
                        $success_message = 'Mata kuliah berhasil ditambahkan!';
                    }
                }
                break;

            case 'edit':
                $id = (int)$_POST['id'];
                $kode_mk = trim($_POST['kode_mk']);
                $nama_mk = trim($_POST['nama_mk']);
                $sks = (int)$_POST['sks'];
                $semester = trim($_POST['semester']);
                $tahun_ajaran = trim($_POST['tahun_ajaran']);

                if (empty($kode_mk) || empty($nama_mk) || $sks < 1 || $sks > 6 || empty($semester) || empty($tahun_ajaran)) {
                    $error_message = 'Semua field harus diisi dengan benar!';
                } else {
                    // Check if kode_mk already exists for other records
                    $existing = $db->selectOne("SELECT id FROM mata_kuliah WHERE kode_mk = ? AND id != ?", [$kode_mk, $id]);
                    if ($existing) {
                        $error_message = 'Kode mata kuliah sudah ada!';
                    } else {
                        $db->query("UPDATE mata_kuliah SET kode_mk = ?, nama_mk = ?, sks = ?, semester = ?, tahun_ajaran = ? WHERE id = ? AND dosen_id = ?", 
                            [$kode_mk, $nama_mk, $sks, $semester, $tahun_ajaran, $id, $user['id']]);
                        $success_message = 'Mata kuliah berhasil diperbarui!';
                    }
                }
                break;

            case 'delete':
                $id = (int)$_POST['id'];
                // Check if there are assignments for this course
                $assignments = $db->selectOne("SELECT COUNT(*) as count FROM tugas WHERE mata_kuliah_id = ?", [$id]);
                if ($assignments['count'] > 0) {
                    $error_message = 'Tidak dapat menghapus mata kuliah yang sudah memiliki tugas!';
                } else {
                    $db->query("DELETE FROM mata_kuliah WHERE id = ? AND dosen_id = ?", [$id, $user['id']]);
                    $success_message = 'Mata kuliah berhasil dihapus!';
                }
                break;
        }
    }
}

// Get mata kuliah data based on role
if (isAdmin()) {
    $mata_kuliah = $db->select("SELECT mk.*, COUNT(e.id) as jumlah_mahasiswa, COUNT(t.id) as jumlah_tugas
        FROM mata_kuliah mk
        LEFT JOIN enrollments e ON mk.id = e.mata_kuliah_id
        LEFT JOIN tugas t ON mk.id = t.mata_kuliah_id
        WHERE mk.dosen_id = ?
        GROUP BY mk.id
        ORDER BY mk.created_at DESC", [$user['id']]);
} else {
    $mata_kuliah = $db->select("SELECT mk.*, u.full_name as dosen_nama, 
        COUNT(t.id) as jumlah_tugas,
        COUNT(pt.id) as tugas_dikumpulkan,
        COUNT(CASE WHEN pt.nilai IS NOT NULL THEN 1 END) as tugas_dinilai
        FROM mata_kuliah mk
        JOIN enrollments e ON mk.id = e.mata_kuliah_id
        JOIN users u ON mk.dosen_id = u.id
        LEFT JOIN tugas t ON mk.id = t.mata_kuliah_id AND t.is_active = 1
        LEFT JOIN pengumpulan_tugas pt ON (t.id = pt.tugas_id AND pt.mahasiswa_id = ?)
        WHERE e.mahasiswa_id = ?
        GROUP BY mk.id
        ORDER BY mk.semester DESC, mk.nama_mk ASC", [$user['id'], $user['id']]);
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= isAdmin() ? 'Kelola Mata Kuliah' : 'Mata Kuliah Saya' ?> - Sistem Tugas Mahasiswa</title>
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
            transition: transform 0.3s ease;
        }
        .card:hover {
            transform: translateY(-2px);
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
        .course-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            transition: all 0.3s ease;
            border-left: 5px solid;
        }
        .course-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
        }
        .course-card.ganjil { border-left-color: #007bff; }
        .course-card.genap { border-left-color: #28a745; }
        .badge-sks {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 8px 12px;
            border-radius: 20px;
            font-weight: 500;
        }
        .stats-mini {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 10px;
            margin: 5px 0;
        }
        .btn-action {
            border-radius: 8px;
            padding: 8px 15px;
            font-size: 0.875rem;
            transition: all 0.3s ease;
        }
        .modal-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        .page-title {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px;
            padding: 2rem;
            margin-bottom: 2rem;
        }
        .progress-bar {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
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
                            <a class="nav-link active" href="mata-kuliah.php">
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
                            <a class="nav-link active" href="mata-kuliah.php">
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
                <!-- Page Title -->
                <div class="page-title">
                    <div class="row align-items-center">
                        <div class="col-md-8">
                            <h2><i class="fas fa-book"></i> <?= isAdmin() ? 'Kelola Mata Kuliah' : 'Mata Kuliah Saya' ?></h2>
                            <p class="mb-0">
                                <?php if (isAdmin()): ?>
                                    Kelola mata kuliah yang Anda ampu
                                <?php else: ?>
                                    Daftar mata kuliah yang Anda ambil semester ini
                                <?php endif; ?>
                            </p>
                        </div>
                        <div class="col-md-4 text-end">
                            <?php if (isAdmin()): ?>
                                <button class="btn btn-light" data-bs-toggle="modal" data-bs-target="#addCourseModal">
                                    <i class="fas fa-plus"></i> Tambah Mata Kuliah
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Alert Messages -->
                <?php if ($success_message): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="fas fa-check-circle"></i> <?= $success_message ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if ($error_message): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-triangle"></i> <?= $error_message ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Mata Kuliah Cards -->
                <?php if (empty($mata_kuliah)): ?>
                    <div class="card">
                        <div class="card-body text-center py-5">
                            <i class="fas fa-book fa-4x text-muted mb-3"></i>
                            <h4>Belum Ada Mata Kuliah</h4>
                            <p class="text-muted">
                                <?php if (isAdmin()): ?>
                                    Klik tombol "Tambah Mata Kuliah" untuk mulai menambahkan mata kuliah.
                                <?php else: ?>
                                    Anda belum terdaftar di mata kuliah manapun. Hubungi admin untuk pendaftaran.
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="row">
                        <?php foreach ($mata_kuliah as $mk): ?>
                            <div class="col-lg-6 col-xl-4 mb-4">
                                <div class="course-card <?= strtolower($mk['semester']) ?> p-4">
                                    <div class="d-flex justify-content-between align-items-start mb-3">
                                        <div>
                                            <h5 class="mb-1"><?= htmlspecialchars($mk['nama_mk']) ?></h5>
                                            <p class="text-muted mb-0"><?= htmlspecialchars($mk['kode_mk']) ?></p>
                                        </div>
                                        <span class="badge-sks"><?= $mk['sks'] ?> SKS</span>
                                    </div>

                                    <div class="mb-3">
                                        <small class="text-muted">
                                            <i class="fas fa-calendar"></i> Semester <?= htmlspecialchars($mk['semester']) ?> - <?= htmlspecialchars($mk['tahun_ajaran']) ?>
                                        </small>
                                        <?php if (!isAdmin()): ?>
                                            <br><small class="text-muted">
                                                <i class="fas fa-user-tie"></i> <?= htmlspecialchars($mk['dosen_nama']) ?>
                                            </small>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Statistics -->
                                    <div class="row mb-3">
                                        <?php if (isAdmin()): ?>
                                            <div class="col-6">
                                                <div class="stats-mini text-center">
                                                    <div class="h6 mb-0"><?= $mk['jumlah_mahasiswa'] ?></div>
                                                    <small class="text-muted">Mahasiswa</small>
                                                </div>
                                            </div>
                                            <div class="col-6">
                                                <div class="stats-mini text-center">
                                                    <div class="h6 mb-0"><?= $mk['jumlah_tugas'] ?></div>
                                                    <small class="text-muted">Tugas</small>
                                                </div>
                                            </div>
                                        <?php else: ?>
                                            <div class="col-4">
                                                <div class="stats-mini text-center">
                                                    <div class="h6 mb-0"><?= $mk['jumlah_tugas'] ?></div>
                                                    <small class="text-muted">Tugas</small>
                                                </div>
                                            </div>
                                            <div class="col-4">
                                                <div class="stats-mini text-center">
                                                    <div class="h6 mb-0"><?= $mk['tugas_dikumpulkan'] ?></div>
                                                    <small class="text-muted">Selesai</small>
                                                </div>
                                            </div>
                                            <div class="col-4">
                                                <div class="stats-mini text-center">
                                                    <div class="h6 mb-0"><?= $mk['tugas_dinilai'] ?></div>
                                                    <small class="text-muted">Dinilai</small>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Progress Bar for Students -->
                                    <?php if (!isAdmin() && $mk['jumlah_tugas'] > 0): ?>
                                        <div class="mb-3">
                                            <div class="d-flex justify-content-between mb-1">
                                                <small class="text-muted">Progress</small>
                                                <small class="text-muted"><?= round(($mk['tugas_dikumpulkan'] / $mk['jumlah_tugas']) * 100) ?>%</small>
                                            </div>
                                            <div class="progress" style="height: 6px;">
                                                <div class="progress-bar" role="progressbar" 
                                                     style="width: <?= round(($mk['tugas_dikumpulkan'] / $mk['jumlah_tugas']) * 100) ?>%">
                                                </div>
                                            </div>
                                        </div>
                                    <?php endif; ?>

                                    <!-- Action Buttons -->
                                    <div class="d-flex gap-2">
                                        <?php if (isAdmin()): ?>
                                            <a href="tugas.php?mata_kuliah_id=<?= $mk['id'] ?>" class="btn btn-primary btn-sm btn-action flex-fill">
                                                <i class="fas fa-tasks"></i> Tugas
                                            </a>
                                            <button class="btn btn-outline-primary btn-sm btn-action" 
                                                    onclick="editCourse(<?= htmlspecialchars(json_encode($mk)) ?>)">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button class="btn btn-outline-danger btn-sm btn-action" 
                                                    onclick="deleteCourse(<?= $mk['id'] ?>, '<?= htmlspecialchars($mk['nama_mk']) ?>')">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        <?php else: ?>
                                            <a href="tugas-saya.php?mata_kuliah_id=<?= $mk['id'] ?>" class="btn btn-primary btn-sm btn-action flex-fill">
                                                <i class="fas fa-tasks"></i> Lihat Tugas
                                            </a>
                                            <a href="nilai-saya.php?mata_kuliah_id=<?= $mk['id'] ?>" class="btn btn-outline-primary btn-sm btn-action">
                                                <i class="fas fa-star"></i> Nilai
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if (isAdmin()): ?>
        <!-- Add Course Modal -->
        <div class="modal fade" id="addCourseModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="fas fa-plus"></i> Tambah Mata Kuliah</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <form method="POST">
                        <div class="modal-body">
                            <input type="hidden" name="action" value="create">
                            
                            <div class="mb-3">
                                <label class="form-label">Kode Mata Kuliah *</label>
                                <input type="text" class="form-control" name="kode_mk" required 
                                       placeholder="Contoh: TI001" maxlength="10">
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Nama Mata Kuliah *</label>
                                <input type="text" class="form-control" name="nama_mk" required 
                                       placeholder="Contoh: Pemrograman Web" maxlength="100">
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label">SKS *</label>
                                        <select class="form-select" name="sks" required>
                                            <option value="">Pilih SKS</option>
                                            <option value="1">1 SKS</option>
                                            <option value="2">2 SKS</option>
                                            <option value="3">3 SKS</option>
                                            <option value="4">4 SKS</option>
                                            <option value="5">5 SKS</option>
                                            <option value="6">6 SKS</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label">Semester *</label>
                                        <select class="form-select" name="semester" required>
                                            <option value="">Pilih Semester</option>
                                            <option value="Ganjil">Ganjil</option>
                                            <option value="Genap">Genap</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Tahun Ajaran *</label>
                                <input type="text" class="form-control" name="tahun_ajaran" required 
                                       placeholder="Contoh: 2024/2025" maxlength="10">
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> Simpan
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Edit Course Modal -->
        <div class="modal fade" id="editCourseModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="fas fa-edit"></i> Edit Mata Kuliah</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <form method="POST">
                        <div class="modal-body">
                            <input type="hidden" name="action" value="edit">
                            <input type="hidden" name="id" id="edit_id">
                            
                            <div class="mb-3">
                                <label class="form-label">Kode Mata Kuliah *</label>
                                <input type="text" class="form-control" name="kode_mk" id="edit_kode_mk" required maxlength="10">
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Nama Mata Kuliah *</label>
                                <input type="text" class="form-control" name="nama_mk" id="edit_nama_mk" required maxlength="100">
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label">SKS *</label>
                                        <select class="form-select" name="sks" id="edit_sks" required>
                                            <option value="">Pilih SKS</option>
                                            <option value="1">1 SKS</option>
                                            <option value="2">2 SKS</option>
                                            <option value="3">3 SKS</option>
                                            <option value="4">4 SKS</option>
                                            <option value="5">5 SKS</option>
                                            <option value="6">6 SKS</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label">Semester *</label>
                                        <select class="form-select" name="semester" id="edit_semester" required>
                                            <option value="">Pilih Semester</option>
                                            <option value="Ganjil">Ganjil</option>
                                            <option value="Genap">Genap</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Tahun Ajaran *</label>
                                <input type="text" class="form-control" name="tahun_ajaran" id="edit_tahun_ajaran" required maxlength="10">
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> Update
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Delete Confirmation Modal -->
        <div class="modal fade" id="deleteCourseModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header bg-danger text-white">
                        <h5 class="modal-title"><i class="fas fa-trash"></i> Hapus Mata Kuliah</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <form method="POST">
                        <div class="modal-body">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" id="delete_id">
                            
                            <div class="text-center">
                                <i class="fas fa-exclamation-triangle fa-4x text-danger mb-3"></i>
                                <h5>Apakah Anda yakin?</h5>
                                <p>Mata kuliah "<span id="delete_course_name"></span>" akan dihapus secara permanen.</p>
                                <div class="alert alert-warning">
                                    <i class="fas fa-info-circle"></i> 
                                    Pastikan tidak ada tugas yang terkait dengan mata kuliah ini.
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                            <button type="submit" class="btn btn-danger">
                                <i class="fas fa-trash"></i> Ya, Hapus
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
    
    <?php if (isAdmin()): ?>
    <script>
        function editCourse(course) {
            document.getElementById('edit_id').value = course.id;
            document.getElementById('edit_kode_mk').value = course.kode_mk;
            document.getElementById('edit_nama_mk').value = course.nama_mk;
            document.getElementById('edit_sks').value = course.sks;
            document.getElementById('edit_semester').value = course.semester;
            document.getElementById('edit_tahun_ajaran').value = course.tahun_ajaran;
            
            new bootstrap.Modal(document.getElementById('editCourseModal')).show();
        }

        function deleteCourse(id, name) {
            document.getElementById('delete_id').value = id;
            document.getElementById('delete_course_name').textContent = name;
            
            new bootstrap.Modal(document.getElementById('deleteCourseModal')).show();
        }

        // Auto dismiss alerts after 5 seconds
        setTimeout(function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(function(alert) {
                const bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            });
        }, 5000);
    </script>
    <?php endif; ?>
</body>
</html>
