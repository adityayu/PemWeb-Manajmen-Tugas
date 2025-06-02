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
    
    // DEBUG: Tampilkan informasi POST dan FILES
    error_log("POST data: " . print_r($_POST, true));
    error_log("FILES data: " . print_r($_FILES, true));
    
    try {
        // Validasi file upload
        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            $upload_errors = [
                UPLOAD_ERR_OK => 'No error',
                UPLOAD_ERR_INI_SIZE => 'File terlalu besar (php.ini)',
                UPLOAD_ERR_FORM_SIZE => 'File terlalu besar (form)',
                UPLOAD_ERR_PARTIAL => 'File hanya terupload sebagian',
                UPLOAD_ERR_NO_FILE => 'Tidak ada file yang diupload',
                UPLOAD_ERR_NO_TMP_DIR => 'Direktori temporary tidak ada',
                UPLOAD_ERR_CANT_WRITE => 'Gagal menulis file',
                UPLOAD_ERR_EXTENSION => 'Upload dihentikan oleh ekstensi'
            ];
            
            $error_code = $_FILES['file']['error'] ?? 'tidak ada file';
            $error_message = isset($upload_errors[$error_code]) ? $upload_errors[$error_code] : 'Error tidak dikenal';
            
            throw new Exception('File harus diupload. Error: ' . $error_message . ' (Code: ' . $error_code . ')');
        }

        $file = $_FILES['file'];
        $file_name = $file['name'];
        $file_size = $file['size'];
        $file_tmp = $file['tmp_name'];
        
        // DEBUG: Log file info
        error_log("File info - Name: $file_name, Size: $file_size, Tmp: $file_tmp");
        
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
            if (!mkdir($upload_dir, 0755, true)) {
                throw new Exception('Gagal membuat direktori upload');
            }
        }

        // Generate nama file unik
        $new_filename = $user['nim'] . '_' . $tugas_id . '_' . time() . '.' . $file_extension;
        $file_path = $upload_dir . $new_filename;

        // DEBUG: Log path info
        error_log("Upload path: $file_path");

        // Upload file
        if (!move_uploaded_file($file_tmp, $file_path)) {
            throw new Exception('Gagal mengupload file. Periksa permission direktori.');
        }

        // Cek apakah deadline sudah lewat saat submit
        $is_late = strtotime($tugas['deadline']) < time();
        
        // DEBUG: Log database insert
        error_log("Inserting to database - tugas_id: $tugas_id, mahasiswa_id: {$user['id']}, file_name: $file_name, file_path: $file_path, file_size: $file_size, is_late: " . ($is_late ? 'true' : 'false'));
        
        // Simpan ke database dengan try-catch terpisah
        try {
            $result = $db->insert("INSERT INTO pengumpulan_tugas (tugas_id, mahasiswa_id, file_name, file_path, file_size, is_late) VALUES (?, ?, ?, ?, ?, ?)", [
                $tugas_id,
                $user['id'],
                $file_name,
                $file_path,
                $file_size,
                $is_late ? 1 : 0  // Pastikan boolean dikonversi ke integer
            ]);
            
            error_log("Database insert result: " . ($result ? 'success' : 'failed'));
            
            if (!$result) {
                throw new Exception('Gagal menyimpan data ke database');
            }
            
        } catch (Exception $db_error) {
            // Hapus file yang sudah diupload jika database gagal
            if (file_exists($file_path)) {
                unlink($file_path);
            }
            throw new Exception('Database error: ' . $db_error->getMessage());
        }

        // Buat notifikasi untuk dosen
        try {
            $mata_kuliah = $db->selectOne("SELECT dosen_id FROM mata_kuliah WHERE id = ?", [$tugas['mata_kuliah_id']]);
            
            if ($mata_kuliah) {
                $dosen_id = $mata_kuliah['dosen_id'];
                
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
            }
        } catch (Exception $notif_error) {
            // Log error tapi jangan gagalkan proses utama
            error_log("Notification error: " . $notif_error->getMessage());
        }

        $_SESSION['success'] = 'Tugas berhasil dikumpulkan!';
        header('Location: tugas-detail.php?id=' . $tugas_id);
        exit;

    } catch (Exception $e) {
        $_SESSION['error'] = $e->getMessage();
        error_log("Upload error: " . $e->getMessage());
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

// DEBUG: Tampilkan informasi untuk debugging
if (isset($_GET['debug']) && $_GET['debug'] == '1') {
    echo "<pre>";
    echo "User ID: " . $user['id'] . "\n";
    echo "Tugas ID: " . $tugas_id . "\n";
    echo "Already submitted: " . ($already_submitted ? 'Yes' : 'No') . "\n";
    echo "Past deadline: " . ($is_past_deadline ? 'Yes' : 'No') . "\n";
    echo "POST method: " . ($_SERVER['REQUEST_METHOD'] === 'POST' ? 'Yes' : 'No') . "\n";
    echo "Max file size: " . formatBytes($tugas['max_file_size'] ?: 5242880) . "\n";
    echo "Allowed extensions: " . ($tugas['allowed_extensions'] ?: 'pdf,doc,docx,zip,rar') . "\n";
    echo "</pre>";
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
        /* CSS yang sama seperti sebelumnya */
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
    <!-- Navbar dan konten HTML yang sama seperti sebelumnya -->
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
        <!-- Alert Messages -->
        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-triangle"></i> <?= $_SESSION['error'] ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>

        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle"></i> <?= $_SESSION['success'] ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php unset($_SESSION['success']); ?>
        <?php endif; ?>

        <div class="row">
            <div class="col-md-8 mx-auto">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">
                            <i class="fas fa-upload"></i> Kumpulkan Tugas
                        </h5>
                    </div>
                    <div class="card-body">
                        <!-- Form upload dengan perbaikan -->
                        <?php if (!$already_submitted && !$is_past_deadline): ?>
                            <form id="uploadForm" method="POST" enctype="multipart/form-data">
                                <!-- Hidden field untuk memastikan form terkirim -->
                                <input type="hidden" name="submit_tugas" value="1">
                                
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
            if (uploadArea) {
                uploadArea.addEventListener('click', function() {
                    fileInput.click();
                });
            }

            // Handle file selection
            if (fileInput) {
                fileInput.addEventListener('change', function() {
                    if (this.files.length > 0) {
                        handleFile(this.files[0]);
                    }
                });
            }

            // Handle file display
            function handleFile(file) {
                if (file && fileName && fileSize && fileInfo && uploadArea && submitBtn) {
                    fileName.textContent = file.name;
                    fileSize.textContent = formatBytes(file.size);
                    fileInfo.style.display = 'block';
                    uploadArea.style.display = 'none';
                    submitBtn.disabled = false;
                }
            }

            // Remove file
            if (removeFile) {
                removeFile.addEventListener('click', function() {
                    fileInput.value = '';
                    fileInfo.style.display = 'none';
                    uploadArea.style.display = 'block';
                    submitBtn.disabled = true;
                });
            }

            // Handle form submission
            if (uploadForm) {
                uploadForm.addEventListener('submit', function(e) {
                    if (submitBtn) {
                        submitBtn.disabled = true;
                        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Mengupload...';
                    }
                    
                    // Let the form submit normally (tidak preventDefault)
                    // return true untuk melanjutkan submit
                    return true;
                });
            }
        });
    </script>
</body>
</html>
