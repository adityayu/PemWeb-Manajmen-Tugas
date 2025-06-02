<?php
require_once 'includes/auth.php';
requireLogin();
requireAdmin(); // Hanya admin/dosen yang bisa akses

$user = getCurrentUser();

// Handle form submission untuk penilaian
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'grade') {
        $submission_id = (int)$_POST['submission_id'];
        $nilai = (float)$_POST['nilai'];
        $feedback = trim($_POST['feedback']);
        
        // Validasi
        if ($nilai < 0 || $nilai > 100) {
            $error = "Nilai harus antara 0-100";
        } else {
            // Update nilai dan feedback
            $result = $db->execute("
                UPDATE pengumpulan_tugas 
                SET nilai = ?, feedback = ?, graded_at = NOW(), graded_by = ?, status = 'graded'
                WHERE id = ?", 
                [$nilai, $feedback, $user['id'], $submission_id]);
            
            if ($result) {
                $success = "Nilai berhasil disimpan!";
                
                // Tambah notifikasi untuk mahasiswa
                $submission = $db->selectOne("
                    SELECT pt.mahasiswa_id, t.judul, mk.nama_mk 
                    FROM pengumpulan_tugas pt
                    JOIN tugas t ON pt.tugas_id = t.id
                    JOIN mata_kuliah mk ON t.mata_kuliah_id = mk.id
                    WHERE pt.id = ?", [$submission_id]);
                
                if ($submission) {
                    $db->execute("
                        INSERT INTO notifications (user_id, title, message, type) 
                        VALUES (?, ?, ?, 'success')", [
                        $submission['mahasiswa_id'],
                        'Tugas Dinilai',
                        "Tugas '{$submission['judul']}' pada mata kuliah '{$submission['nama_mk']}' telah dinilai dengan nilai {$nilai}"
                    ]);
                }
            } else {
                $error = "Gagal menyimpan nilai";
            }
        }
    } elseif ($_POST['action'] === 'revision') {
        $submission_id = (int)$_POST['submission_id'];
        $feedback = trim($_POST['feedback']);
        
        $result = $db->execute("
            UPDATE pengumpulan_tugas 
            SET feedback = ?, graded_at = NOW(), graded_by = ?, status = 'revision'
            WHERE id = ?", 
            [$feedback, $user['id'], $submission_id]);
        
        if ($result) {
            $success = "Tugas dikembalikan untuk revisi!";
            
            // Tambah notifikasi untuk mahasiswa
            $submission = $db->selectOne("
                SELECT pt.mahasiswa_id, t.judul, mk.nama_mk 
                FROM pengumpulan_tugas pt
                JOIN tugas t ON pt.tugas_id = t.id
                JOIN mata_kuliah mk ON t.mata_kuliah_id = mk.id
                WHERE pt.id = ?", [$submission_id]);
            
            if ($submission) {
                $db->execute("
                    INSERT INTO notifications (user_id, title, message, type) 
                    VALUES (?, ?, ?, 'warning')", [
                    $submission['mahasiswa_id'],
                    'Tugas Perlu Revisi',
                    "Tugas '{$submission['judul']}' pada mata kuliah '{$submission['nama_mk']}' perlu direvisi"
                ]);
            }
        } else {
            $error = "Gagal mengembalikan tugas";
        }
    }
}

// Get filter parameters
$mata_kuliah_filter = $_GET['mata_kuliah'] ?? '';
$status_filter = $_GET['status'] ?? '';
$search = $_GET['search'] ?? '';

// Build query conditions
$conditions = ["mk.dosen_id = ?"];
$params = [$user['id']];

if ($mata_kuliah_filter) {
    $conditions[] = "mk.id = ?";
    $params[] = $mata_kuliah_filter;
}

if ($status_filter) {
    $conditions[] = "pt.status = ?";
    $params[] = $status_filter;
}

if ($search) {
    $conditions[] = "(u.full_name LIKE ? OR u.nim LIKE ? OR t.judul LIKE ?)";
    $search_param = "%{$search}%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
}

$where_clause = "WHERE " . implode(" AND ", $conditions);

// Get submissions for grading
$submissions = $db->select("
    SELECT pt.*, t.judul as tugas_judul, t.deadline, mk.nama_mk, mk.kode_mk,
           u.full_name as mahasiswa_nama, u.nim, u.email as mahasiswa_email,
           grader.full_name as grader_name
    FROM pengumpulan_tugas pt
    JOIN tugas t ON pt.tugas_id = t.id
    JOIN mata_kuliah mk ON t.mata_kuliah_id = mk.id
    JOIN users u ON pt.mahasiswa_id = u.id
    LEFT JOIN users grader ON pt.graded_by = grader.id
    {$where_clause}
    ORDER BY 
        CASE pt.status 
            WHEN 'submitted' THEN 1 
            WHEN 'revision' THEN 2 
            WHEN 'graded' THEN 3 
        END,
        pt.submitted_at DESC", $params);

// Get mata kuliah for filter
$mata_kuliah_list = $db->select("
    SELECT * FROM mata_kuliah 
    WHERE dosen_id = ? 
    ORDER BY nama_mk", [$user['id']]);

// Statistics
$stats = [
    'total' => $db->selectOne("
        SELECT COUNT(*) as count FROM pengumpulan_tugas pt
        JOIN tugas t ON pt.tugas_id = t.id
        JOIN mata_kuliah mk ON t.mata_kuliah_id = mk.id
        WHERE mk.dosen_id = ?", [$user['id']])['count'],
    'belum_dinilai' => $db->selectOne("
        SELECT COUNT(*) as count FROM pengumpulan_tugas pt
        JOIN tugas t ON pt.tugas_id = t.id
        JOIN mata_kuliah mk ON t.mata_kuliah_id = mk.id
        WHERE mk.dosen_id = ? AND pt.status = 'submitted'", [$user['id']])['count'],
    'sudah_dinilai' => $db->selectOne("
        SELECT COUNT(*) as count FROM pengumpulan_tugas pt
        JOIN tugas t ON pt.tugas_id = t.id
        JOIN mata_kuliah mk ON t.mata_kuliah_id = mk.id
        WHERE mk.dosen_id = ? AND pt.status = 'graded'", [$user['id']])['count'],
    'revisi' => $db->selectOne("
        SELECT COUNT(*) as count FROM pengumpulan_tugas pt
        JOIN tugas t ON pt.tugas_id = t.id
        JOIN mata_kuliah mk ON t.mata_kuliah_id = mk.id
        WHERE mk.dosen_id = ? AND pt.status = 'revision'", [$user['id']])['count']
];
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Penilaian - Sistem Tugas Mahasiswa</title>
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
        .stat-card.warning { border-left-color: #ffc107; }
        .stat-card.success { border-left-color: #28a745; }
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
        .submission-card {
            border-left: 4px solid #ddd;
            transition: all 0.3s ease;
        }
        .submission-card.submitted {
            border-left-color: #ffc107;
        }
        .submission-card.graded {
            border-left-color: #28a745;
        }
        .submission-card.revision {
            border-left-color: #dc3545;
        }
        .grade-form {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 1rem;
            margin-top: 1rem;
        }
        .file-info {
            background: #e9ecef;
            border-radius: 8px;
            padding: 0.5rem;
            margin: 0.5rem 0;
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
                            <i class="fas fa-book"></i> Mata Kuliah
                        </a>
                        <a class="nav-link" href="tugas.php">
                            <i class="fas fa-tasks"></i> Kelola Tugas
                        </a>
                        <a class="nav-link active" href="penilaian.php">
                            <i class="fas fa-star"></i> Penilaian
                        </a>
                        <a class="nav-link" href="mahasiswa.php">
                            <i class="fas fa-users"></i> Mahasiswa
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
                        <h2><i class="fas fa-star"></i> Penilaian Tugas</h2>
                        <p class="text-muted">Kelola penilaian tugas mahasiswa</p>
                    </div>
                </div>

                <!-- Alert Messages -->
                <?php if (isset($success)): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="fas fa-check-circle"></i> <?= htmlspecialchars($success) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if (isset($error)): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Statistics Cards -->
                <div class="row mb-4">
                    <div class="col-md-3 mb-3">
                        <div class="stat-card primary p-3">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <h6 class="text-muted">Total Pengumpulan</h6>
                                    <h3 class="mb-0"><?= $stats['total'] ?></h3>
                                </div>
                                <i class="fas fa-upload fa-2x text-primary"></i>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <div class="stat-card warning p-3">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <h6 class="text-muted">Belum Dinilai</h6>
                                    <h3 class="mb-0"><?= $stats['belum_dinilai'] ?></h3>
                                </div>
                                <i class="fas fa-clock fa-2x text-warning"></i>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <div class="stat-card success p-3">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <h6 class="text-muted">Sudah Dinilai</h6>
                                    <h3 class="mb-0"><?= $stats['sudah_dinilai'] ?></h3>
                                </div>
                                <i class="fas fa-check fa-2x text-success"></i>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <div class="stat-card danger p-3">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <h6 class="text-muted">Perlu Revisi</h6>
                                    <h3 class="mb-0"><?= $stats['revisi'] ?></h3>
                                </div>
                                <i class="fas fa-redo fa-2x text-danger"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filter Section -->
                <div class="card mb-4">
                    <div class="card-body">
                        <form method="GET" class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">Mata Kuliah</label>
                                <select name="mata_kuliah" class="form-select">
                                    <option value="">Semua Mata Kuliah</option>
                                    <?php foreach ($mata_kuliah_list as $mk): ?>
                                        <option value="<?= $mk['id'] ?>" <?= $mata_kuliah_filter == $mk['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($mk['kode_mk'] . ' - ' . $mk['nama_mk']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Status</label>
                                <select name="status" class="form-select">
                                    <option value="">Semua Status</option>
                                    <option value="submitted" <?= $status_filter == 'submitted' ? 'selected' : '' ?>>Belum Dinilai</option>
                                    <option value="graded" <?= $status_filter == 'graded' ? 'selected' : '' ?>>Sudah Dinilai</option>
                                    <option value="revision" <?= $status_filter == 'revision' ? 'selected' : '' ?>>Perlu Revisi</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Cari</label>
                                <input type="text" name="search" class="form-control" placeholder="Nama/NIM/Judul Tugas" value="<?= htmlspecialchars($search) ?>">
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
                    </div>
                </div>

                <!-- Submissions List -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-list"></i> Daftar Pengumpulan Tugas</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($submissions)): ?>
                            <div class="text-center py-5">
                                <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                                <h5>Tidak ada pengumpulan tugas</h5>
                                <p class="text-muted">Belum ada tugas yang dikumpulkan sesuai dengan filter yang dipilih.</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($submissions as $submission): ?>
                                <div class="submission-card <?= $submission['status'] ?> card mb-3">
                                    <div class="card-body">
                                        <div class="row">
                                            <div class="col-md-8">
                                                <div class="d-flex justify-content-between align-items-start mb-2">
                                                    <h6 class="mb-1"><?= htmlspecialchars($submission['tugas_judul']) ?></h6>
                                                    <?php
                                                    $status_class = [
                                                        'submitted' => 'warning',
                                                        'graded' => 'success',
                                                        'revision' => 'danger'
                                                    ];
                                                    $status_text = [
                                                        'submitted' => 'Belum Dinilai',
                                                        'graded' => 'Sudah Dinilai',
                                                        'revision' => 'Perlu Revisi'
                                                    ];
                                                    ?>
                                                    <span class="badge bg-<?= $status_class[$submission['status']] ?>">
                                                        <?= $status_text[$submission['status']] ?>
                                                    </span>
                                                </div>
                                                
                                                <p class="text-muted mb-1">
                                                    <i class="fas fa-book"></i> <?= htmlspecialchars($submission['kode_mk'] . ' - ' . $submission['nama_mk']) ?>
                                                </p>
                                                
                                                <p class="mb-1">
                                                    <i class="fas fa-user"></i> <strong><?= htmlspecialchars($submission['mahasiswa_nama']) ?></strong>
                                                    <span class="text-muted">(<?= htmlspecialchars($submission['nim']) ?>)</span>
                                                </p>
                                                
                                                <div class="file-info">
                                                    <i class="fas fa-file"></i> <?= htmlspecialchars($submission['file_name']) ?>
                                                    <span class="text-muted">(<?= formatFileSize($submission['file_size']) ?>)</span>
                                                    <a href="download.php?file=<?= urlencode($submission['file_path']) ?>" class="btn btn-sm btn-outline-primary ms-2">
                                                        <i class="fas fa-download"></i> Download
                                                    </a>
                                                </div>
                                                
                                                <small class="text-muted">
                                                    <i class="fas fa-clock"></i> Dikumpulkan: <?= date('d/m/Y H:i', strtotime($submission['submitted_at'])) ?>
                                                    <?php if ($submission['is_late']): ?>
                                                        <span class="badge bg-danger ms-1">Terlambat</span>
                                                    <?php endif; ?>
                                                </small>
                                            </div>
                                            
                                            <div class="col-md-4">
                                                <?php if ($submission['status'] === 'graded'): ?>
                                                    <div class="text-center mb-2">
                                                        <div class="badge bg-success fs-5 mb-2">
                                                            Nilai: <?= number_format($submission['nilai'], 1) ?>
                                                        </div>
                                                        <?php if ($submission['feedback']): ?>
                                                            <div class="small text-muted">
                                                                <strong>Feedback:</strong><br>
                                                                <?= nl2br(htmlspecialchars($submission['feedback'])) ?>
                                                            </div>
                                                        <?php endif; ?>
                                                        <div class="small text-muted mt-1">
                                                            Dinilai oleh: <?= htmlspecialchars($submission['grader_name']) ?><br>
                                                            <?= date('d/m/Y H:i', strtotime($submission['graded_at'])) ?>
                                                        </div>
                                                    </div>
                                                    <button class="btn btn-sm btn-outline-primary w-100" onclick="toggleGradeForm(<?= $submission['id'] ?>)">
                                                        <i class="fas fa-edit"></i> Edit Nilai
                                                    </button>
                                                    
                                                <?php elseif ($submission['status'] === 'revision'): ?>
                                                    <div class="text-center mb-2">
                                                        <div class="badge bg-danger fs-6 mb-2">Perlu Revisi</div>
                                                        <?php if ($submission['feedback']): ?>
                                                            <div class="small text-muted">
                                                                <strong>Catatan Revisi:</strong><br>
                                                                <?= nl2br(htmlspecialchars($submission['feedback'])) ?>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                    <button class="btn btn-sm btn-success w-100 mb-1" onclick="toggleGradeForm(<?= $submission['id'] ?>)">
                                                        <i class="fas fa-star"></i> Beri Nilai
                                                    </button>
                                                    
                                                <?php else: ?>
                                                    <div class="d-grid gap-2">
                                                        <button class="btn btn-success" onclick="toggleGradeForm(<?= $submission['id'] ?>)">
                                                            <i class="fas fa-star"></i> Beri Nilai
                                                        </button>
                                                        <button class="btn btn-warning" onclick="toggleRevisionForm(<?= $submission['id'] ?>)">
                                                            <i class="fas fa-redo"></i> Minta Revisi
                                                        </button>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        
                                        <!-- Grade Form -->
                                        <div id="gradeForm<?= $submission['id'] ?>" class="grade-form" style="display: none;">
                                            <form method="POST">
                                                <input type="hidden" name="action" value="grade">
                                                <input type="hidden" name="submission_id" value="<?= $submission['id'] ?>">
                                                
                                                <div class="row">
                                                    <div class="col-md-6">
                                                        <label class="form-label">Nilai (0-100)</label>
                                                        <input type="number" name="nilai" class="form-control" min="0" max="100" step="0.1" 
                                                               value="<?= $submission['nilai'] ?>" required>
                                                    </div>
                                                    <div class="col-md-6">
                                                        <label class="form-label">Feedback (Opsional)</label>
                                                        <textarea name="feedback" class="form-control" rows="3" placeholder="Berikan feedback untuk mahasiswa..."><?= htmlspecialchars($submission['feedback']) ?></textarea>
                                                    </div>
                                                </div>
                                                
                                                <div class="mt-3">
                                                    <button type="submit" class="btn btn-success">
                                                        <i class="fas fa-save"></i> Simpan Nilai
                                                    </button>
                                                    <button type="button" class="btn btn-secondary" onclick="toggleGradeForm(<?= $submission['id'] ?>)">
                                                        Batal
                                                    </button>
                                                </div>
                                            </form>
                                        </div>
                                        
                                        <!-- Revision Form -->
                                        <div id="revisionForm<?= $submission['id'] ?>" class="grade-form" style="display: none;">
                                            <form method="POST">
                                                <input type="hidden" name="action" value="revision">
                                                <input type="hidden" name="submission_id" value="<?= $submission['id'] ?>">
                                                
                                                <div class="mb-3">
                                                    <label class="form-label">Catatan Revisi</label>
                                                    <textarea name="feedback" class="form-control" rows="4" 
                                                              placeholder="Jelaskan apa yang perlu direvisi..." required><?= htmlspecialchars($submission['feedback']) ?></textarea>
                                                </div>
                                                
                                                <button type="submit" class="btn btn-warning">
                                                    <i class="fas fa-redo"></i> Kembalikan untuk Revisi
                                                </button>
                                                <button type="button" class="btn btn-secondary" onclick="toggleRevisionForm(<?= $submission['id'] ?>)">
                                                    Batal
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
    <script>
        function toggleGradeForm(submissionId) {
            const form = document.getElementById(`gradeForm${submissionId}`);
            const revisionForm = document.getElementById(`revisionForm${submissionId}`);
            
            if (revisionForm.style.display !== 'none') {
                revisionForm.style.display = 'none';
            }
            
           form.style.display = form.style.display === 'none' ? 'block' : 'none';
            
            if (form.style.display === 'block') {
                form.querySelector('textarea[name="feedback"]').focus();
            }
        }
        
        // Auto-hide alerts after 5 seconds
        document.addEventListener('DOMContentLoaded', function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(function(alert) {
                setTimeout(function() {
                    if (alert && alert.classList.contains('show')) {
                        const bsAlert = new bootstrap.Alert(alert);
                        bsAlert.close();
                    }
                }, 5000);
            });
        });
        
        // Confirm before submitting revision
        document.querySelectorAll('form').forEach(form => {
            if (form.querySelector('input[name="action"][value="revision"]')) {
                form.addEventListener('submit', function(e) {
                    if (!confirm('Yakin ingin mengembalikan tugas ini untuk revisi?')) {
                        e.preventDefault();
                    }
                });
            }
        });
        
        // Validate grade input
        document.querySelectorAll('input[name="nilai"]').forEach(input => {
            input.addEventListener('input', function() {
                const value = parseFloat(this.value);
                if (value < 0) this.value = 0;
                if (value > 100) this.value = 100;
            });
        });
    </script>
</body>
</html>

<?php
// Helper function untuk format file size
function formatFileSize($bytes) {
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    } else {
        return $bytes . ' bytes';
    }
}
?>
