<?php
require_once 'includes/auth.php';
requireLogin();

// Pastikan hanya mahasiswa yang bisa akses
if (isAdmin()) {
    header('Location: dashboard.php');
    exit;
}

$user = getCurrentUser();

// Validasi parameter ID tugas
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    $_SESSION['error'] = 'ID tugas tidak valid';
    header('Location: tugas-saya.php');
    exit;
}

$tugas_id = (int)$_GET['id'];

// Cek apakah mahasiswa terdaftar di mata kuliah ini
$tugas = $db->selectOne("
    SELECT 
        t.*,
        mk.nama_mk,
        mk.kode_mk,
        pt.id as pengumpulan_id,
        pt.submitted_at,
        pt.nilai
    FROM tugas t
    JOIN mata_kuliah mk ON t.mata_kuliah_id = mk.id
    JOIN enrollments e ON mk.id = e.mata_kuliah_id
    LEFT JOIN pengumpulan_tugas pt ON (t.id = pt.tugas_id AND pt.mahasiswa_id = ?)
    WHERE t.id = ? AND e.mahasiswa_id = ? AND t.is_active = 1
", [$user['id'], $tugas_id, $user['id']]);

if (!$tugas) {
    $_SESSION['error'] = 'Tugas tidak ditemukan atau Anda tidak memiliki akses';
    header('Location: tugas-saya.php');
    exit;
}

// Cek apakah sudah melewati deadline
$is_past_deadline = strtotime($tugas['deadline']) < time();

// Cek apakah sudah pernah mengumpulkan
$already_submitted = !empty($tugas['pengumpulan_id']);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$already_submitted && !$is_past_deadline) {
    try {
        // Validasi file upload
        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('File harus diupload');
        }

        $file = $_FILES['file'];
        $file_name = $file['name'];
        $file_size = $file['size'];
        $file_tmp = $file['tmp_name'];
        
        // Validasi ukuran file (max 5MB sesuai database)
        $max_size = $tugas['max_file_size'] ?: 5242880; // 5MB default
        if ($file_size > $max_size) {
            throw new Exception('Ukuran file terlalu besar. Maksimal ' . formatBytes($max_size));
        }

        // Validasi ekstensi file
        $allowed_extensions = explode(',', $tugas['allowed_extensions'] ?: 'pdf,doc,docx,zip,rar');
        $file_extension = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
        
        if (!in_array($file_extension, $allowed_extensions)) {
            throw new Exception('Ekstensi file tidak diizinkan. Ekstensi yang diizinkan: ' . implode(', ', $allowed_extensions));
        }

        // Buat direktori upload jika belum ada
        $upload_dir = "uploads/tugas/" . $tugas_id . "/";
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }

        // Generate nama file unik
        $new_filename = $user['nim'] . '_' . $tugas_id . '_' . time() . '.' . $file_extension;
        $file_path = $upload_dir . $new_filename;

        // Upload file
        if (!move_uploaded_file($file_tmp, $file_path)) {
            throw new Exception('Gagal mengupload file');
        }

        // Simpan ke database
        $is_late = strtotime($tugas['deadline']) < time();
        
        $db->insert("INSERT INTO pengumpulan_tugas (tugas_id, mahasiswa_id, file_name, file_path, file_size, is_late) VALUES (?, ?, ?, ?, ?, ?)", [
            $tugas_id,
            $user['id'],
            $file_name,
            $file_path,
            $file_size,
            $is_late
        ]);

        // Buat notifikasi untuk dosen
        $dosen_id = $db->selectOne("SELECT dosen_id FROM mata_kuliah WHERE id = ?", [$tugas['mata_kuliah_id']])['dosen_id'];
        
        $notification_message = "Mahasiswa {$user['full_name']} ({$user['nim']}) telah mengumpulkan tugas '{$tugas['judul']}' untuk mata kuliah {$tugas['kode_mk']}";
        if ($is_late) {
            $notification_message .= " (TERLAMBAT)";
        }

        $db->insert("INSERT INTO notifications (user_id, title, message, type) VALUES (?, ?, ?, ?)", [
            $dosen_id,
            "Pengumpulan Tugas Baru",
            $notification_message,
            $is_late ? "warning" : "info"
        ]);

        $_SESSION['success'] = 'Tugas berhasil dikumpulkan!';
        header('Location: tugas-detail.php?id=' . $tugas_id);
        exit;

    } catch (Exception $e) {
        $_SESSION['error'] = $e->getMessage();
    }
}

// Function untuk format bytes
function formatBytes($size, $precision = 2) {
    $units = array('B', 'KB', 'MB', 'GB');
    for ($i = 0; $size >= 1024 && $i < count($units) - 1; $i++) {
        $size /= 1024;
    }
    return round($size, $precision) . ' ' . $units[$i];
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kumpulkan Tugas - <?= htmlspecialchars($tugas['judul']) ?></title>
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
            margin-bottom: 2rem;
        }
        .card-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px 15px 0 0 !important;
        }
        .upload-area {
            border: 2px dashed #ddd;
            border-radius: 10px;
            padding: 3rem;
            text-align: center;
            transition: all 0.3s ease;
            cursor: pointer;
            background: #fafafa;
        }
        .upload-area:hover {
            border-color: #667eea;
            background: #f0f4ff;
        }
        .upload-area.dragover {
            border-color: #667eea;
            background: #e8f0ff;
        }
        .upload-icon {
            font-size: 3rem;
            color: #667eea;
            margin-bottom: 1rem;
        }
        .file-info {
            background: #e8f0ff;
            border: 1px solid #667eea;
            border-radius: 8px;
            padding: 1rem;
            margin-top: 1rem;
        }
        .deadline-warning {
            background: linear-gradient(135deg, #ff9a9e 0%, #fecfef 100%);
            border-radius: 10px;
            padding: 1rem;
            margin-bottom: 1.5rem;
        }
        .already-submitted {
            background: linear-gradient(135deg, #a8edea 0%, #fed6e3 100%);
            border-radius: 10px;
            padding: 2rem;
            text-align: center;
        }
        .progress-container {
            display: none;
            margin-top: 1rem;
        }
        .requirements-box {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1.5rem;
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

    <div class="container mt-4">
        <!-- Breadcrumb -->
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="tugas-saya.php">Tugas Saya</a></li>
                <li class="breadcrumb-item"><a href="tugas-detail.php?id=<?= $tugas_id ?>">Detail Tugas</a></li>
                <li class="breadcrumb-item active">Kumpulkan Tugas</li>
            </ol>
        </nav>

        <!-- Alert Messages -->
        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-triangle"></i> <?= $_SESSION['error'] ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>

        <div class="row">
            <div class="col-md-8 mx-auto">
                <!-- Task Info -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">
                            <i class="fas fa-upload"></i> Kumpulkan Tugas
                        </h5>
                    </div>
                    <div class="card-body">
                        <h4><?= htmlspecialchars($tugas['judul']) ?></h4>
                        <p class="text-muted mb-3">
                            <i class="fas fa-book"></i> <?= htmlspecialchars($tugas['kode_mk']) ?> - <?= htmlspecialchars($tugas['nama_mk']) ?>
                        </p>
                        
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <strong>Deadline:</strong><br>
                                <span class="<?= $is_past_deadline ? 'text-danger' : 'text-success' ?>">
                                    <i class="fas fa-calendar-alt"></i> 
                                    <?= date('d/m/Y H:i', strtotime($tugas['deadline'])) ?>
                                    <?php if ($is_past_deadline): ?>
                                        <small>(Sudah Lewat)</small>
                                    <?php endif; ?>
                                </span>
                            </div>
                            <div class="col-md-6">
                                <strong>Status:</strong><br>
                                <?php if ($already_submitted): ?>
                                    <span class="text-success">
                                        <i class="fas fa-check-circle"></i> Sudah Dikumpulkan
                                    </span>
                                <?php elseif ($is_past_deadline): ?>
                                    <span class="text-danger">
                                        <i class="fas fa-times-circle"></i> Terlambat
                                    </span>
                                <?php else: ?>
                                    <span class="text-warning">
                                        <i class="fas fa-clock"></i> Belum Dikumpulkan
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="mb-4">
                            <strong>Deskripsi Tugas:</strong>
                            <p class="mt-2"><?= nl2br(htmlspecialchars($tugas['deskripsi'])) ?></p>
                        </div>

                        <!-- Requirements Box -->
                        <div class="requirements-box">
                            <h6><i class="fas fa-info-circle"></i> Persyaratan Upload:</h6>
                            <ul class="mb-0">
                                <li>Ukuran file maksimal: <strong><?= formatBytes($tugas['max_file_size'] ?: 5242880) ?></strong></li>
                                <li>Ekstensi file yang diizinkan: <strong><?= str_replace(',', ', ', $tugas['allowed_extensions'] ?: 'pdf, doc, docx, zip, rar') ?></strong></li>
                                <li>Pastikan file dapat dibuka dengan baik</li>
                                <li>Nama file sebaiknya menggunakan format yang jelas</li>
                            </ul>
                        </div>

                        <?php if ($already_submitted): ?>
                            <!-- Already Submitted -->
                            <div class="already-submitted">
                                <i class="fas fa-check-circle fa-3x text-success mb-3"></i>
                                <h5>Tugas Sudah Dikumpulkan</h5>
                                <p class="mb-3">
                                    Anda telah mengumpulkan tugas ini pada:<br>
                                    <strong><?= date('d/m/Y H:i', strtotime($tugas['submitted_at'])) ?></strong>
                                </p>
                                <div class="d-flex gap-2 justify-content-center">
                                    <a href="tugas-detail.php?id=<?= $tugas_id ?>" class="btn btn-primary">
                                        <i class="fas fa-eye"></i> Lihat Detail
                                    </a>
                                    <a href="tugas-saya.php" class="btn btn-outline-secondary">
                                        <i class="fas fa-arrow-left"></i> Kembali
                                    </a>
                                </div>
                            </div>

                        <?php elseif ($is_past_deadline): ?>
                            <!-- Past Deadline -->
                            <div class="deadline-warning text-center">
                                <i class="fas fa-exclamation-triangle fa-3x text-warning mb-3"></i>
                                <h5>Deadline Sudah Lewat</h5>
                                <p class="mb-3">
                                    Maaf, deadline untuk tugas ini sudah lewat pada:<br>
                                    <strong><?= date('d/m/Y H:i', strtotime($tugas['deadline'])) ?></strong>
                                </p>
                                <p class="mb-0">
                                    Silakan hubungi dosen pengampu mata kuliah untuk informasi lebih lanjut.
                                </p>
                                <div class="mt-3">
                                    <a href="tugas-saya.php" class="btn btn-outline-dark">
                                        <i class="fas fa-arrow-left"></i> Kembali ke Tugas Saya
                                    </a>
                                </div>
                            </div>

                        <?php else: ?>
                            <!-- Upload Form -->
                            <form id="uploadForm" method="POST" enctype="multipart/form-data">
                                <div class="upload-area" id="uploadArea">
                                    <div class="upload-icon">
                                        <i class="fas fa-cloud-upload-alt"></i>
                                    </div>
                                    <h5>Klik atau Drag & Drop File di Sini</h5>
                                    <p class="text-muted mb-0">
                                        Pilih file yang akan dikumpulkan untuk tugas ini
                                    </p>
                                    <input type="file" id="fileInput" name="file" style="display: none;" required>
                                </div>

                                <div id="fileInfo" class="file-info" style="display: none;">
                                    <div class="d-flex align-items-center justify-content-between">
                                        <div>
                                            <i class="fas fa-file"></i>
                                            <span id="fileName"></span>
                                            <br>
                                            <small class="text-muted" id="fileSize"></small>
                                        </div>
                                        <button type="button" class="btn btn-sm btn-outline-danger" id="removeFile">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    </div>
                                </div>

                                <div class="progress-container">
                                    <div class="progress">
                                        <div class="progress-bar progress-bar-striped progress-bar-animated" 
                                             role="progressbar" style="width: 0%"></div>
                                    </div>
                                    <small class="text-muted mt-1">Mengupload file...</small>
                                </div>

                                <div class="mt-4 d-flex gap-2 justify-content-end">
                                    <a href="tugas-detail.php?id=<?= $tugas_id ?>" class="btn btn-outline-secondary">
                                        <i class="fas fa-arrow-left"></i> Kembali
                                    </a>
                                    <button type="submit" class="btn btn-success" id="submitBtn" disabled>
                                        <i class="fas fa-upload"></i> Kumpulkan Tugas
                                    </button>
                                </div>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const uploadArea = document.getElementById('uploadArea');
            const fileInput = document.getElementById('fileInput');
            const fileInfo = document.getElementById('fileInfo');
            const fileName = document.getElementById('fileName');
            const fileSize = document.getElementById('fileSize');
            const removeFile = document.getElementById('removeFile');
            const submitBtn = document.getElementById('submitBtn');
            const uploadForm = document.getElementById('uploadForm');
            const progressContainer = document.querySelector('.progress-container');
            const progressBar = document.querySelector('.progress-bar');

            // Format file size
            function formatBytes(bytes, decimals = 2) {
                if (bytes === 0) return '0 Bytes';
                const k = 1024;
                const dm = decimals < 0 ? 0 : decimals;
                const sizes = ['Bytes', 'KB', 'MB', 'GB'];
                const i = Math.floor(Math.log(bytes) / Math.log(k));
                return parseFloat((bytes / Math.pow(k, i)).toFixed(dm)) + ' ' + sizes[i];
            }

            // Handle click on upload area
            uploadArea.addEventListener('click', function() {
                fileInput.click();
            });

            // Handle file selection
            fileInput.addEventListener('change', function() {
                handleFile(this.files[0]);
            });

            // Handle drag and drop
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
                    handleFile(files[0]);
                }
            });

            // Handle file display
            function handleFile(file) {
                if (file) {
                    fileName.textContent = file.name;
                    fileSize.textContent = formatBytes(file.size);
                    fileInfo.style.display = 'block';
                    uploadArea.style.display = 'none';
                    submitBtn.disabled = false;
                }
            }

            // Remove file
            removeFile.addEventListener('click', function() {
                fileInput.value = '';
                fileInfo.style.display = 'none';
                uploadArea.style.display = 'block';
                submitBtn.disabled = true;
            });

            // Handle form submission with progress
            uploadForm.addEventListener('submit', function(e) {
                e.preventDefault();
                
                const formData = new FormData(this);
                
                // Show progress
                progressContainer.style.display = 'block';
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Mengupload...';

                // Simulate progress (in real implementation, use XMLHttpRequest for real progress)
                let progress = 0;
                const progressInterval = setInterval(() => {
                    progress += Math.random() * 15;
                    if (progress > 90) progress = 90;
                    progressBar.style.width = progress + '%';
                }, 200);

                // Submit form
                fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                })
                .then(response => {
                    clearInterval(progressInterval);
                    progressBar.style.width = '100%';
                    
                    if (response.ok) {
                        // Redirect will be handled by PHP
                        window.location.reload();
                    } else {
                        throw new Error('Upload failed');
                    }
                })
                .catch(error => {
                    clearInterval(progressInterval);
                    progressContainer.style.display = 'none';
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = '<i class="fas fa-upload"></i> Kumpulkan Tugas';
                    alert('Terjadi kesalahan saat mengupload file. Silakan coba lagi.');
                });
            });

            // Check deadline countdown
            const deadline = new Date('<?= $tugas['deadline'] ?>');
            const now = new Date();
            const timeDiff = deadline - now;
            
            if (timeDiff > 0 && timeDiff < 24 * 60 * 60 * 1000) { // Less than 24 hours
                const hours = Math.floor(timeDiff / (1000 * 60 * 60));
                const minutes = Math.floor((timeDiff % (1000 * 60 * 60)) / (1000 * 60));
                
                if (hours < 2) {
                    const warning = document.createElement('div');
                    warning.className = 'alert alert-warning';
                    warning.innerHTML = `
                        <i class="fas fa-exclamation-triangle"></i> 
                        <strong>Peringatan:</strong> Deadline tinggal ${hours} jam ${minutes} menit lagi!
                    `;
                    document.querySelector('.card-body').insertBefore(warning, document.querySelector('.requirements-box'));
                }
            }
        });
    </script>
</body>
</html>
