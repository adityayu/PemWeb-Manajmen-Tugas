<?php
require_once 'includes/auth.php';
requireLogin();

// Pastikan hanya mahasiswa yang bisa akses
if (isAdmin()) {
    header('Location: dashboard.php');
    exit;
}

$user = getCurrentUser();
$tugas_id = $_GET['id'] ?? null;

if (!$tugas_id) {
    header('Location: tugas-saya.php');
    exit;
}

// Handle file upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_tugas'])) {
    $upload_error = '';
    $upload_success = false;
    
    if (isset($_FILES['file_tugas']) && $_FILES['file_tugas']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['file_tugas'];
        $file_name = $file['name'];
        $file_tmp = $file['tmp_name'];
        $file_size = $file['size'];
        $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
        
        // Get tugas info for validation
        $tugas_info = $db->select("SELECT * FROM tugas WHERE id = ? AND is_active = 1", [$tugas_id]);
        if (empty($tugas_info)) {
            $upload_error = 'Tugas tidak ditemukan!';
        } else {
            $tugas = $tugas_info[0];
            $allowed_extensions = explode(',', $tugas['allowed_extensions']);
            $max_size = $tugas['max_file_size'];
            
            // Validate file extension
            if (!in_array($file_ext, $allowed_extensions)) {
                $upload_error = 'Format file tidak diizinkan! Format yang diizinkan: ' . implode(', ', $allowed_extensions);
            }
            // Validate file size
            elseif ($file_size > $max_size) {
                $upload_error = 'Ukuran file terlalu besar! Maksimal ' . number_format($max_size/1024/1024, 2) . ' MB';
            } else {
                // Create upload directory if not exists
                $upload_dir = 'uploads/tugas/' . $tugas_id . '/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }
                
                // Generate unique filename
                $new_filename = $user['nim'] . '_' . time() . '.' . $file_ext;
                $file_path = $upload_dir . $new_filename;
                
                if (move_uploaded_file($file_tmp, $file_path)) {
                    // Check if student already submitted
                    $existing = $db->select("SELECT id FROM pengumpulan_tugas WHERE tugas_id = ? AND mahasiswa_id = ?", [$tugas_id, $user['id']]);
                    
                    $is_late = (strtotime($tugas['deadline']) < time()) ? 1 : 0;
                    
                    if (empty($existing)) {
                        // Insert new submission
                        $result = $db->insert("INSERT INTO pengumpulan_tugas (tugas_id, mahasiswa_id, file_name, file_path, file_size, is_late) VALUES (?, ?, ?, ?, ?, ?)", 
                            [$tugas_id, $user['id'], $file_name, $file_path, $file_size, $is_late]);
                    } else {
                        // Update existing submission
                        $result = $db->update("UPDATE pengumpulan_tugas SET file_name = ?, file_path = ?, file_size = ?, submitted_at = CURRENT_TIMESTAMP, is_late = ? WHERE tugas_id = ? AND mahasiswa_id = ?", 
                            [$file_name, $file_path, $file_size, $is_late, $tugas_id, $user['id']]);
                    }
                    
                    if ($result) {
                        $upload_success = true;
                        // Create notification
                        $db->insert("INSERT INTO notifications (user_id, title, message, type) VALUES (?, ?, ?, ?)", 
                            [$user['id'], 'Tugas Berhasil Dikumpulkan', 'Tugas "' . $tugas['judul'] . '" berhasil dikumpulkan.', 'success']);
                    } else {
                        $upload_error = 'Gagal menyimpan data ke database!';
                        unlink($file_path); // Delete uploaded file
                    }
                } else {
                    $upload_error = 'Gagal mengupload file!';
                }
            }
        }
    } else {
        $upload_error = 'Silakan pilih file untuk diupload!';
    }
}

// Get tugas detail with submission info
$tugas_detail = $db->select("
    SELECT 
        t.*,
        mk.nama_mk,
        mk.kode_mk,
        mk.sks,
        u.full_name as dosen_name,
        pt.id as pengumpulan_id,
        pt.file_name,
        pt.file_path,
        pt.file_size,
        pt.submitted_at,
        pt.nilai,
        pt.feedback,
        pt.graded_at,
        pt.is_late,
        pt.status as submission_status,
        ug.full_name as grader_name,
        CASE 
            WHEN pt.id IS NOT NULL THEN 'submitted'
            WHEN t.deadline < NOW() THEN 'overdue'
            ELSE 'pending'
        END as task_status
    FROM tugas t
    JOIN mata_kuliah mk ON t.mata_kuliah_id = mk.id
    JOIN users u ON mk.dosen_id = u.id
    LEFT JOIN pengumpulan_tugas pt ON (t.id = pt.tugas_id AND pt.mahasiswa_id = ?)
    LEFT JOIN users ug ON pt.graded_by = ug.id
    WHERE t.id = ? AND t.is_active = 1
", [$user['id'], $tugas_id]);

// Check if student is enrolled in this course
$enrollment_check = $db->select("
    SELECT e.id FROM enrollments e
    JOIN mata_kuliah mk ON e.mata_kuliah_id = mk.id
    JOIN tugas t ON mk.id = t.mata_kuliah_id
    WHERE e.mahasiswa_id = ? AND t.id = ?
", [$user['id'], $tugas_id]);

if (empty($tugas_detail) || empty($enrollment_check)) {
    header('Location: tugas-saya.php');
    exit;
}

$tugas = $tugas_detail[0];

// Calculate time remaining
$deadline = strtotime($tugas['deadline']);
$now = time();
$time_remaining = $deadline - $now;
$is_deadline_passed = $time_remaining <= 0;

// Get other submissions count (for statistics)
$submission_stats = $db->select("
    SELECT 
        COUNT(*) as total_submissions,
        AVG(nilai) as avg_grade
    FROM pengumpulan_tugas pt
    WHERE pt.tugas_id = ? AND pt.nilai IS NOT NULL
", [$tugas_id]);

$stats = $submission_stats[0] ?? ['total_submissions' => 0, 'avg_grade' => 0];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($tugas['judul']) ?> - Sistem Tugas Mahasiswa</title>
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
            margin-bottom: 1.5rem;
        }
        .card-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px 15px 0 0 !important;
        }
        .status-badge {
            padding: 0.75rem 1.5rem;
            border-radius: 25px;
            font-size: 1rem;
            font-weight: 600;
        }
        .deadline-warning {
            background: linear-gradient(135deg, #ff9a56 0%, #ff6b6b 100%);
            color: white;
            border-radius: 10px;
            padding: 1rem;
            margin: 1rem 0;
            animation: pulse 2s infinite;
        }
        @keyframes pulse {
            0% { box-shadow: 0 0 0 0 rgba(255, 107, 107, 0.7); }
            70% { box-shadow: 0 0 0 10px rgba(255, 107, 107, 0); }
            100% { box-shadow: 0 0 0 0 rgba(255, 107, 107, 0); }
        }
        .countdown {
            font-size: 1.2rem;
            font-weight: bold;
            color: #dc3545;
        }
        .upload-area {
            border: 2px dashed #dee2e6;
            border-radius: 10px;
            padding: 2rem;
            text-align: center;
            transition: all 0.3s ease;
            background: #f8f9fa;
        }
        .upload-area.dragover {
            border-color: #667eea;
            background: #e3f2fd;
        }
        .file-info {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 1rem;
            margin: 1rem 0;
        }
        .grade-display {
            font-size: 3rem;
            font-weight: bold;
            color: #0d6efd;
            text-align: center;
        }
        .feedback-box {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            border-radius: 10px;
            padding: 1rem;
            margin: 1rem 0;
        }
        .description-content {
            line-height: 1.8;
            font-size: 1.1rem;
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
                <!-- Breadcrumb -->
                <nav aria-label="breadcrumb" class="mb-4">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                        <li class="breadcrumb-item"><a href="tugas-saya.php">Tugas Saya</a></li>
                        <li class="breadcrumb-item active"><?= htmlspecialchars($tugas['judul']) ?></li>
                    </ol>
                </nav>

                <!-- Success/Error Messages -->
                <?php if (isset($upload_success) && $upload_success): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="fas fa-check-circle"></i> Tugas berhasil dikumpulkan!
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if (isset($upload_error) && $upload_error): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($upload_error) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <div class="row">
                    <!-- Task Details -->
                    <div class="col-lg-8">
                        <!-- Task Header -->
                        <div class="card">
                            <div class="card-header">
                                <h4 class="mb-0"><i class="fas fa-tasks"></i> Detail Tugas</h4>
                            </div>
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start mb-3">
                                    <div>
                                        <h2 class="mb-2"><?= htmlspecialchars($tugas['judul']) ?></h2>
                                        <p class="text-muted mb-0">
                                            <i class="fas fa-book"></i> <?= htmlspecialchars($tugas['kode_mk']) ?> - <?= htmlspecialchars($tugas['nama_mk']) ?>
                                            <span class="mx-2">|</span>
                                            <i class="fas fa-user"></i> <?= htmlspecialchars($tugas['dosen_name']) ?>
                                        </p>
                                    </div>
                                    <?php
                                    $status_class = [
                                        'submitted' => 'bg-success',
                                        'overdue' => 'bg-danger',
                                        'pending' => 'bg-warning text-dark'
                                    ];
                                    $status_text = [
                                        'submitted' => 'Sudah Dikumpulkan',
                                        'overdue' => 'Terlambat',
                                        'pending' => 'Belum Dikumpulkan'
                                    ];
                                    ?>
                                    <span class="badge <?= $status_class[$tugas['task_status']] ?> status-badge">
                                        <?= $status_text[$tugas['task_status']] ?>
                                    </span>
                                </div>

                                <!-- Deadline Warning -->
                                <?php if ($tugas['task_status'] === 'pending' && $time_remaining <= 86400 && $time_remaining > 0): ?>
                                    <div class="deadline-warning">
                                        <div class="d-flex align-items-center">
                                            <i class="fas fa-exclamation-triangle fa-2x me-3"></i>
                                            <div>
                                                <h6 class="mb-1">Deadline Hampir Habis!</h6>
                                                <div class="countdown" id="countdown"></div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <!-- Task Description -->
                                <div class="mb-4">
                                    <h5><i class="fas fa-align-left"></i> Deskripsi Tugas</h5>
                                    <div class="description-content">
                                        <?= nl2br(htmlspecialchars($tugas['deskripsi'])) ?>
                                    </div>
                                </div>

                                <!-- Task Information -->
                                <div class="row">
                                    <div class="col-md-6">
                                        <h6><i class="fas fa-calendar-alt"></i> Deadline</h6>
                                        <p><?= date('l, d F Y \p\u\k\u\l H:i', strtotime($tugas['deadline'])) ?></p>
                                    </div>
                                    <div class="col-md-6">
                                        <h6><i class="fas fa-file"></i> Format File</h6>
                                        <p><?= strtoupper(str_replace(',', ', ', $tugas['allowed_extensions'])) ?></p>
                                    </div>
                                    <div class="col-md-6">
                                        <h6><i class="fas fa-hdd"></i> Ukuran Maksimal</h6>
                                        <p><?= number_format($tugas['max_file_size']/1024/1024, 2) ?> MB</p>
                                    </div>
                                    <div class="col-md-6">
                                        <h6><i class="fas fa-users"></i> Total Pengumpulan</h6>
                                        <p><?= $stats['total_submissions'] ?> mahasiswa</p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Submission Form or Details -->
                        <?php if ($tugas['task_status'] === 'pending' && !$is_deadline_passed): ?>
                            <!-- Upload Form -->
                            <div class="card">
                                <div class="card-header">
                                    <h5 class="mb-0"><i class="fas fa-upload"></i> Kumpulkan Tugas</h5>
                                </div>
                                <div class="card-body">
                                    <form method="POST" enctype="multipart/form-data" id="uploadForm">
                                        <div class="upload-area" id="uploadArea">
                                            <i class="fas fa-cloud-upload-alt fa-3x text-muted mb-3"></i>
                                            <h5>Pilih File atau Drag & Drop</h5>
                                            <p class="text-muted">Format: <?= strtoupper(str_replace(',', ', ', $tugas['allowed_extensions'])) ?> | Maksimal: <?= number_format($tugas['max_file_size']/1024/1024, 2) ?> MB</p>
                                            <input type="file" name="file_tugas" id="fileInput" class="d-none" accept=".<?= str_replace(',', ',.', $tugas['allowed_extensions']) ?>" required>
                                            <button type="button" class="btn btn-primary" onclick="document.getElementById('fileInput').click()">
                                                <i class="fas fa-folder-open"></i> Pilih File
                                            </button>
                                        </div>
                                        <div id="filePreview" class="mt-3" style="display: none;"></div>
                                        <div class="mt-3">
                                            <button type="submit" name="submit_tugas" class="btn btn-success btn-lg" id="submitBtn" disabled>
                                                <i class="fas fa-upload"></i> Kumpulkan Tugas
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        <?php elseif ($tugas['pengumpulan_id']): ?>
                            <!-- Submission Details -->
                            <div class="card">
                                <div class="card-header">
                                    <h5 class="mb-0"><i class="fas fa-file-alt"></i> Detail Pengumpulan</h5>
                                </div>
                                <div class="card-body">
                                    <div class="file-info">
                                        <div class="row align-items-center">
                                            <div class="col-md-8">
                                                <h6><i class="fas fa-file"></i> <?= htmlspecialchars($tugas['file_name']) ?></h6>
                                                <p class="text-muted mb-0">
                                                    Ukuran: <?= number_format($tugas['file_size']/1024/1024, 2) ?> MB |
                                                    Dikumpulkan: <?= date('d/m/Y H:i', strtotime($tugas['submitted_at'])) ?>
                                                    <?php if ($tugas['is_late']): ?>
                                                        <span class="badge bg-danger ms-2">Terlambat</span>
                                                    <?php endif; ?>
                                                </p>
                                            </div>
                                            <div class="col-md-4 text-end">
                                                <a href="<?= htmlspecialchars($tugas['file_path']) ?>" class="btn btn-outline-primary" download>
                                                    <i class="fas fa-download"></i> Download
                                                </a>
                                            </div>
                                        </div>
                                    </div>

                                    <?php if ($tugas['nilai'] !== null): ?>
                                        <!-- Grade Display -->
                                        <div class="text-center my-4">
                                            <div class="grade-display"><?= number_format($tugas['nilai'], 1) ?></div>
                                            <p class="text-muted">Nilai Anda</p>
                                            <?php if ($stats['avg_grade'] > 0): ?>
                                                <small class="text-muted">Rata-rata kelas: <?= number_format($stats['avg_grade'], 1) ?></small>
                                            <?php endif; ?>
                                        </div>

                                        <?php if ($tugas['feedback']): ?>
                                            <div class="feedback-box">
                                                <h6><i class="fas fa-comment"></i> Feedback dari Dosen</h6>
                                                <p class="mb-0"><?= nl2br(htmlspecialchars($tugas['feedback'])) ?></p>
                                                <?php if ($tugas['graded_at']): ?>
                                                    <small class="text-muted">
                                                        Dinilai pada: <?= date('d/m/Y H:i', strtotime($tugas['graded_at'])) ?>
                                                        <?php if ($tugas['grader_name']): ?>
                                                            oleh <?= htmlspecialchars($tugas['grader_name']) ?>
                                                        <?php endif; ?>
                                                    </small>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <div class="alert alert-info">
                                            <i class="fas fa-info-circle"></i> Tugas Anda sedang dalam proses penilaian.
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php else: ?>
                            <!-- Deadline Passed -->
                            <div class="card">
                                <div class="card-body text-center">
                                    <i class="fas fa-clock fa-4x text-danger mb-3"></i>
                                    <h4 class="text-danger">Deadline Telah Berakhir</h4>
                                    <p class="text-muted">Waktu pengumpulan tugas telah habis pada <?= date('d/m/Y H:i', strtotime($tugas['deadline'])) ?></p>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Sidebar Info -->
                    <div class="col-lg-4">
                        <!-- Quick Info -->
                        <div class="card">
                            <div class="card-header">
                                <h6 class="mb-0"><i class="fas fa-info-circle"></i> Informasi Singkat</h6>
                            </div>
                            <div class="card-body">
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Status:</span>
                                    <span class="badge <?= $status_class[$tugas['task_status']] ?>">
                                        <?= $status_text[$tugas['task_status']] ?>
                                    </span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Deadline:</span>
                                    <span><?= date('d/m/Y', strtotime($tugas['deadline'])) ?></span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Mata Kuliah:</span>
                                    <span><?= htmlspecialchars($tugas['kode_mk']) ?></span>
                                </div>
                                <?php if ($tugas['nilai'] !== null): ?>
                                    <div class="d-flex justify-content-between mb-2">
                                        <span>Nilai:</span>
                                        <span class="fw-bold text-primary"><?= number_format($tugas['nilai'], 1) ?></span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Actions -->
                        <div class="card">
                            <div class="card-header">
                                <h6 class="mb-0"><i class="fas fa-cog"></i> Aksi</h6>
                            </div>
                            <div class="card-body">
                                <div class="d-grid gap-2">
                                    <a href="tugas-saya.php" class="btn btn-outline-secondary">
                                        <i class="fas fa-arrow-left"></i> Kembali ke Daftar Tugas
                                    </a>
                                    <?php if ($tugas['pengumpulan_id'] && $tugas['file_path']): ?>
                                        <a href="<?= htmlspecialchars($tugas['file_path']) ?>" class="btn btn-primary" download>
                                            <i class="fas fa-download"></i> Download File Saya
                                        </a>
                                    <?php endif; ?>
                                    <a href="mata-kuliah-detail.php?id=<?= $tugas['mata_kuliah_id'] ?>" class="btn btn-outline-info">
                                        <i class="fas fa-book"></i> Lihat Mata Kuliah
                                    </a>
                                </div>
                            </div>
                        </div>

                        <!-- Help -->
                        <div class="card">
                            <div class="card-header">
                                <h6 class="mb-0"><i class="fas fa-question-circle"></i> Bantuan</h6>
                            </div>
                            <div class="card-body">
                                <small class="text-muted">
                                    <p><strong>Tips Pengumpulan:</strong></p>
                                    <ul class="ps-3">
                                        <li>Pastikan format file sesuai</li>
                                        <li>Periksa ukuran file</li>
                                        <li>Kumpulkan sebelum deadline</li>
                                        <li>Simpan backup file Anda</li>
                                    </ul>
                                </small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
    <script>
       // Countdown timer
<?php if ($tugas['task_status'] === 'pending' && $time_remaining > 0): ?>
function updateCountdown() {
    const deadline = new Date('<?= date('Y-m-d H:i:s', strtotime($tugas['deadline'])) ?>').getTime();
    const now = new Date().getTime();
    const distance = deadline - now;

    if (distance > 0) {
        const days = Math.floor(distance / (1000 * 60 * 60 * 24));
        const hours = Math.floor((distance % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
        const minutes = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));
        const seconds = Math.floor((distance % (1000 * 60)) / 1000);

        let countdownText = '';
        if (days > 0) countdownText += `${days} hari `;
        if (hours > 0) countdownText += `${hours} jam `;
        if (minutes > 0) countdownText += `${minutes} menit `;
        countdownText += `${seconds} detik`;

        document.getElementById('countdown').innerHTML = countdownText;
    } else {
        document.getElementById('countdown').innerHTML = 'Waktu habis!';
        location.reload();
    }
}

// Update countdown every second
setInterval(updateCountdown, 1000);
updateCountdown();
<?php endif; ?>

// File upload handling
const fileInput = document.getElementById('fileInput');
const uploadArea = document.getElementById('uploadArea');
const filePreview = document.getElementById('filePreview');
const submitBtn = document.getElementById('submitBtn');

if (fileInput && uploadArea) {
    // Drag and drop functionality
    uploadArea.addEventListener('dragover', function(e) {
        e.preventDefault();
        uploadArea.classList.add('dragover');
    });

    uploadArea.addEventListener('dragleave', function(e) {
        e.preventDefault();
        uploadArea.classList.remove('dragover');
    });

    uploadArea.addEventListener('drop', function(e) {
        e.preventDefault();
        uploadArea.classList.remove('dragover');
        const files = e.dataTransfer.files;
        if (files.length > 0) {
            fileInput.files = files;
            handleFileSelect();
        }
    });

    // File input change
    fileInput.addEventListener('change', handleFileSelect);

    function handleFileSelect() {
        const file = fileInput.files[0];
        if (file) {
            // File size validation
            const maxSize = <?= $tugas['max_file_size'] ?>;
            if (file.size > maxSize) {
                alert('Ukuran file terlalu besar! Maksimal <?= number_format($tugas['max_file_size']/1024/1024, 2) ?> MB');
                fileInput.value = '';
                return;
            }

            // File extension validation
            const allowedExtensions = '<?= $tugas['allowed_extensions'] ?>'.split(',');
            const fileExtension = file.name.split('.').pop().toLowerCase();
            if (!allowedExtensions.includes(fileExtension)) {
                alert('Format file tidak diizinkan! Format yang diizinkan: <?= strtoupper(str_replace(',', ', ', $tugas['allowed_extensions'])) ?>');
                fileInput.value = '';
                return;
            }

            // Show file preview
            showFilePreview(file);
            submitBtn.disabled = false;
        } else {
            hideFilePreview();
            submitBtn.disabled = true;
        }
    }

    function showFilePreview(file) {
        const sizeInMB = (file.size / 1024 / 1024).toFixed(2);
        filePreview.innerHTML = `
            <div class="file-info">
                <div class="row align-items-center">
                    <div class="col-md-8">
                        <h6><i class="fas fa-file"></i> ${file.name}</h6>
                        <p class="text-muted mb-0">Ukuran: ${sizeInMB} MB</p>
                    </div>
                    <div class="col-md-4 text-end">
                        <button type="button" class="btn btn-outline-danger btn-sm" onclick="clearFile()">
                            <i class="fas fa-times"></i> Hapus
                        </button>
                    </div>
                </div>
            </div>
        `;
        filePreview.style.display = 'block';
    }

    function hideFilePreview() {
        filePreview.style.display = 'none';
        filePreview.innerHTML = '';
    }

    // Clear file function
    window.clearFile = function() {
        fileInput.value = '';
        hideFilePreview();
        submitBtn.disabled = true;
    };

    // Form submission with loading state
    document.getElementById('uploadForm').addEventListener('submit', function(e) {
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Mengupload...';
        submitBtn.disabled = true;
    });
}

// Auto-dismiss alerts after 5 seconds
document.addEventListener('DOMContentLoaded', function() {
    setTimeout(function() {
        const alerts = document.querySelectorAll('.alert');
        alerts.forEach(function(alert) {
            const bsAlert = new bootstrap.Alert(alert);
            if (bsAlert) {
                bsAlert.close();
            }
        });
    }, 5000);
});

// Format file size helper
function formatFileSize(bytes) {
    if (bytes === 0) return '0 Bytes';
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
}
</script>

    <!-- Footer -->
    <footer class="mt-5 py-4 bg-light">
        <div class="container">
            <div class="row">
                <div class="col-md-6">
                    <p class="mb-0">&copy; 2024 Sistem Tugas Mahasiswa. All rights reserved.</p>
                </div>
                <div class="col-md-6 text-end">
                    <small class="text-muted">Versi 1.0</small>
                </div>
            </div>
        </div>
    </footer>

    <!-- Loading Overlay -->
    <div id="loadingOverlay" class="d-none position-fixed top-0 start-0 w-100 h-100" style="background: rgba(255,255,255,0.8); z-index: 9999;">
        <div class="d-flex justify-content-center align-items-center h-100">
            <div class="text-center">
                <div class="spinner-border text-primary" style="width: 3rem; height: 3rem;" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
                <p class="mt-3">Mengupload file...</p>
            </div>
        </div>
    </div>

</body>
</html>
