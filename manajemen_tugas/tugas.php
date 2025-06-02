<?php
require_once 'includes/auth.php';
requireAdmin();

$user = getCurrentUser();
$message = '';
$error = '';

// Handle form submissions
if ($_POST) {
    if (!$auth->validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid CSRF token';
    } else {
        $action = $_POST['action'] ?? '';
        
        if ($action === 'create') {
            // Validasi input
            $mata_kuliah_id = (int)$_POST['mata_kuliah_id'];
            $judul = trim($_POST['judul']);
            $deskripsi = trim($_POST['deskripsi']);
            $deadline = $_POST['deadline'];
            $max_file_size = (int)$_POST['max_file_size'] * 1024 * 1024; // Convert MB to bytes
            $allowed_extensions = $_POST['allowed_extensions'];

            if (empty($judul) || empty($deskripsi) || empty($deadline)) {
                $error = 'Semua field wajib harus diisi';
            } else {
                // Cek apakah mata kuliah milik dosen ini
                $mk_check = $db->selectOne(
                    "SELECT id FROM mata_kuliah WHERE id = ? AND dosen_id = ?", 
                    [$mata_kuliah_id, $user['id']]
                );
                
                if (!$mk_check) {
                    $error = 'Mata kuliah tidak valid';
                } else {
                    try {
                        $db->execute(
                            "INSERT INTO tugas (mata_kuliah_id, judul, deskripsi, deadline, max_file_size, allowed_extensions) VALUES (?, ?, ?, ?, ?, ?)",
                            [$mata_kuliah_id, $judul, $deskripsi, $deadline, $max_file_size, $allowed_extensions]
                        );
                        $message = 'Tugas berhasil ditambahkan';
                    } catch (Exception $e) {
                        $error = 'Error: ' . $e->getMessage();
                    }
                }
            }
        } elseif ($action === 'update') {
            $tugas_id = (int)$_POST['tugas_id'];
            $judul = trim($_POST['judul']);
            $deskripsi = trim($_POST['deskripsi']);
            $deadline = $_POST['deadline'];
            $max_file_size = (int)$_POST['max_file_size'] * 1024 * 1024;
            $allowed_extensions = $_POST['allowed_extensions'];
            $is_active = isset($_POST['is_active']) ? 1 : 0;

            if (empty($judul) || empty($deskripsi) || empty($deadline)) {
                $error = 'Semua field wajib harus diisi';
            } else {
                // Cek apakah tugas milik dosen ini
                $tugas_check = $db->selectOne(
                    "SELECT t.id FROM tugas t JOIN mata_kuliah mk ON t.mata_kuliah_id = mk.id WHERE t.id = ? AND mk.dosen_id = ?", 
                    [$tugas_id, $user['id']]
                );
                
                if (!$tugas_check) {
                    $error = 'Tugas tidak valid';
                } else {
                    try {
                        $db->execute(
                            "UPDATE tugas SET judul = ?, deskripsi = ?, deadline = ?, max_file_size = ?, allowed_extensions = ?, is_active = ? WHERE id = ?",
                            [$judul, $deskripsi, $deadline, $max_file_size, $allowed_extensions, $is_active, $tugas_id]
                        );
                        $message = 'Tugas berhasil diupdate';
                    } catch (Exception $e) {
                        $error = 'Error: ' . $e->getMessage();
                    }
                }
            }
        } elseif ($action === 'delete') {
            $tugas_id = (int)$_POST['tugas_id'];
            
            // Cek apakah tugas milik dosen ini
            $tugas_check = $db->selectOne(
                "SELECT t.id FROM tugas t JOIN mata_kuliah mk ON t.mata_kuliah_id = mk.id WHERE t.id = ? AND mk.dosen_id = ?", 
                [$tugas_id, $user['id']]
            );
            
            if (!$tugas_check) {
                $error = 'Tugas tidak valid';
            } else {
                try {
                    $db->execute("DELETE FROM tugas WHERE id = ?", [$tugas_id]);
                    $message = 'Tugas berhasil dihapus';
                } catch (Exception $e) {
                    $error = 'Error: ' . $e->getMessage();
                }
            }
        }
    }
}

// Get mata kuliah milik dosen ini
$mata_kuliah_list = $db->select(
    "SELECT * FROM mata_kuliah WHERE dosen_id = ? ORDER BY nama_mk", 
    [$user['id']]
);

// Get daftar tugas
$search = $_GET['search'] ?? '';
$mata_kuliah_filter = $_GET['mata_kuliah'] ?? '';

$where_conditions = ["mk.dosen_id = ?"];
$params = [$user['id']];

if (!empty($search)) {
    $where_conditions[] = "(t.judul LIKE ? OR t.deskripsi LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if (!empty($mata_kuliah_filter)) {
    $where_conditions[] = "t.mata_kuliah_id = ?";
    $params[] = $mata_kuliah_filter;
}

$where_clause = implode(' AND ', $where_conditions);

$tugas_list = $db->select("
    SELECT t.*, mk.nama_mk, mk.kode_mk,
           COUNT(pt.id) as total_submissions,
           COUNT(CASE WHEN pt.nilai IS NOT NULL THEN 1 END) as graded_submissions
    FROM tugas t
    JOIN mata_kuliah mk ON t.mata_kuliah_id = mk.id
    LEFT JOIN pengumpulan_tugas pt ON t.id = pt.tugas_id
    WHERE $where_clause
    GROUP BY t.id
    ORDER BY t.created_at DESC", $params);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kelola Tugas - Sistem Tugas Mahasiswa</title>
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
        .btn-gradient {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            color: white;
        }
        .btn-gradient:hover {
            background: linear-gradient(135deg, #5a67d8 0%, #6b46c1 100%);
            color: white;
        }
        .assignment-card {
            transition: transform 0.3s ease;
            border-left: 4px solid #007bff;
        }
        .assignment-card:hover {
            transform: translateY(-2px);
        }
        .deadline-badge {
            font-size: 0.8em;
        }
        .stats-badge {
            font-size: 0.9em;
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
                            <i class="fas fa-book"></i> Mata Kuliah
                        </a>
                        <a class="nav-link active" href="tugas.php">
                            <i class="fas fa-tasks"></i> Kelola Tugas
                        </a>
                        <a class="nav-link" href="penilaian.php">
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
                    <h2><i class="fas fa-tasks"></i> Kelola Tugas</h2>
                    <button type="button" class="btn btn-gradient" data-bs-toggle="modal" data-bs-target="#addAssignmentModal">
                        <i class="fas fa-plus"></i> Tambah Tugas
                    </button>
                </div>

                <!-- Alert Messages -->
                <?php if ($message): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="fas fa-check-circle"></i> <?= htmlspecialchars($message) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($error) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Filter Section -->
                <div class="card mb-4">
                    <div class="card-body">
                        <form method="GET" class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">Cari Tugas</label>
                                <input type="text" class="form-control" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Cari berdasarkan judul atau deskripsi...">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Filter Mata Kuliah</label>
                                <select class="form-select" name="mata_kuliah">
                                    <option value="">Semua Mata Kuliah</option>
                                    <?php foreach ($mata_kuliah_list as $mk): ?>
                                        <option value="<?= $mk['id'] ?>" <?= $mata_kuliah_filter == $mk['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($mk['kode_mk'] . ' - ' . $mk['nama_mk']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4 d-flex align-items-end">
                                <button type="submit" class="btn btn-primary me-2">
                                    <i class="fas fa-search"></i> Filter
                                </button>
                                <a href="tugas.php" class="btn btn-outline-secondary">
                                    <i class="fas fa-times"></i> Reset
                                </a>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Assignments List -->
                <div class="row">
                    <?php if (empty($tugas_list)): ?>
                        <div class="col-12">
                            <div class="card text-center">
                                <div class="card-body py-5">
                                    <i class="fas fa-tasks fa-3x text-muted mb-3"></i>
                                    <h5>Belum ada tugas</h5>
                                    <p class="text-muted">Mulai dengan menambahkan tugas pertama Anda.</p>
                                    <button type="button" class="btn btn-gradient" data-bs-toggle="modal" data-bs-target="#addAssignmentModal">
                                        <i class="fas fa-plus"></i> Tambah Tugas
                                    </button>
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
                        <?php foreach ($tugas_list as $tugas): ?>
                            <div class="col-md-6 col-lg-4 mb-4">
                                <div class="card assignment-card h-100">
                                    <div class="card-header">
                                        <div class="d-flex justify-content-between align-items-start">
                                            <div>
                                                <h6 class="mb-1"><?= htmlspecialchars($tugas['judul']) ?></h6>
                                                <small><?= htmlspecialchars($tugas['kode_mk'] . ' - ' . $tugas['nama_mk']) ?></small>
                                            </div>
                                            <span class="badge <?= $tugas['is_active'] ? 'bg-success' : 'bg-secondary' ?>">
                                                <?= $tugas['is_active'] ? 'Aktif' : 'Nonaktif' ?>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="card-body">
                                        <p class="card-text text-muted small">
                                            <?= htmlspecialchars(substr($tugas['deskripsi'], 0, 100)) ?>
                                            <?= strlen($tugas['deskripsi']) > 100 ? '...' : '' ?>
                                        </p>
                                        
                                        <div class="mb-3">
                                            <span class="deadline-badge badge <?= strtotime($tugas['deadline']) < time() ? 'bg-danger' : 'bg-warning' ?>">
                                                <i class="fas fa-clock"></i> <?= date('d/m/Y H:i', strtotime($tugas['deadline'])) ?>
                                            </span>
                                        </div>

                                        <div class="row text-center mb-3">
                                            <div class="col-6">
                                                <div class="stats-badge badge bg-primary w-100">
                                                    <i class="fas fa-upload"></i> <?= $tugas['total_submissions'] ?> Pengumpulan
                                                </div>
                                            </div>
                                            <div class="col-6">
                                                <div class="stats-badge badge bg-success w-100">
                                                    <i class="fas fa-star"></i> <?= $tugas['graded_submissions'] ?> Dinilai
                                                </div>
                                            </div>
                                        </div>

                                        <div class="d-flex gap-2">
                                            <button type="button" class="btn btn-outline-primary btn-sm flex-fill" 
                                                    onclick="editAssignment(<?= htmlspecialchars(json_encode($tugas)) ?>)">
                                                <i class="fas fa-edit"></i> Edit
                                            </button>
                                            <a href="penilaian.php?tugas_id=<?= $tugas['id'] ?>" class="btn btn-outline-success btn-sm flex-fill">
                                                <i class="fas fa-star"></i> Nilai
                                            </a>
                                            <button type="button" class="btn btn-outline-danger btn-sm" 
                                                    onclick="deleteAssignment(<?= $tugas['id'] ?>, '<?= htmlspecialchars($tugas['judul']) ?>')">
                                                <i class="fas fa-trash"></i>
                                            </button>
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

    <!-- Add Assignment Modal -->
    <div class="modal fade" id="addAssignmentModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-plus"></i> Tambah Tugas Baru</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="create">
                        <input type="hidden" name="csrf_token" value="<?= $auth->generateCSRFToken() ?>">
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Mata Kuliah <span class="text-danger">*</span></label>
                                <select class="form-select" name="mata_kuliah_id" required>
                                    <option value="">Pilih Mata Kuliah</option>
                                    <?php foreach ($mata_kuliah_list as $mk): ?>
                                        <option value="<?= $mk['id'] ?>">
                                            <?= htmlspecialchars($mk['kode_mk'] . ' - ' . $mk['nama_mk']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Judul Tugas <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="judul" required>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Deskripsi <span class="text-danger">*</span></label>
                            <textarea class="form-control" name="deskripsi" rows="4" required></textarea>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Deadline <span class="text-danger">*</span></label>
                                <input type="datetime-local" class="form-control" name="deadline" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Maksimal Ukuran File (MB)</label>
                                <input type="number" class="form-control" name="max_file_size" value="5" min="1" max="50">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Ekstensi File yang Diizinkan</label>
                            <input type="text" class="form-control" name="allowed_extensions" 
                                   value="pdf,doc,docx,txt" 
                                   placeholder="Contoh: pdf,doc,docx,txt">
                            <div class="form-text">Pisahkan dengan koma, tanpa spasi</div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-gradient">
                            <i class="fas fa-save"></i> Simpan Tugas
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Assignment Modal -->
    <div class="modal fade" id="editAssignmentModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-edit"></i> Edit Tugas</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="editAssignmentForm">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="tugas_id" id="edit_tugas_id">
                        <input type="hidden" name="csrf_token" value="<?= $auth->generateCSRFToken() ?>">
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Mata Kuliah <span class="text-danger">*</span></label>
                                <select class="form-select" name="mata_kuliah_id" id="edit_mata_kuliah_id" required>
                                    <option value="">Pilih Mata Kuliah</option>
                                    <?php foreach ($mata_kuliah_list as $mk): ?>
                                        <option value="<?= $mk['id'] ?>">
                                            <?= htmlspecialchars($mk['kode_mk'] . ' - ' . $mk['nama_mk']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Judul Tugas <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="judul" id="edit_judul" required>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Deskripsi <span class="text-danger">*</span></label>
                            <textarea class="form-control" name="deskripsi" id="edit_deskripsi" rows="4" required></textarea>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Deadline <span class="text-danger">*</span></label>
                                <input type="datetime-local" class="form-control" name="deadline" id="edit_deadline" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Maksimal Ukuran File (MB)</label>
                                <input type="number" class="form-control" name="max_file_size" id="edit_max_file_size" min="1" max="50">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Ekstensi File yang Diizinkan</label>
                            <input type="text" class="form-control" name="allowed_extensions" id="edit_allowed_extensions">
                            <div class="form-text">Pisahkan dengan koma, tanpa spasi</div>
                        </div>

                        <div class="mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="is_active" id="edit_is_active" value="1">
                                <label class="form-check-label" for="edit_is_active">
                                    Tugas Aktif (mahasiswa dapat mengumpulkan)
                                </label>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-gradient">
                            <i class="fas fa-save"></i> Update Tugas
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal fade" id="deleteModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-exclamation-triangle text-danger"></i> Konfirmasi Hapus</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Apakah Anda yakin ingin menghapus tugas <strong id="deleteAssignmentName"></strong>?</p>
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle"></i>
                        <strong>Peringatan:</strong> Semua data pengumpulan dan penilaian yang terkait dengan tugas ini akan ikut terhapus dan tidak dapat dikembalikan.
                    </div>
                </div>
                <div class="modal-footer">
                    <form method="POST" id="deleteForm">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="tugas_id" id="delete_tugas_id">
                        <input type="hidden" name="csrf_token" value="<?= $auth->generateCSRFToken() ?>">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-danger">
                            <i class="fas fa-trash"></i> Hapus Tugas
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
    <script>
        function editAssignment(tugas) {
            document.getElementById('edit_tugas_id').value = tugas.id;
            document.getElementById('edit_mata_kuliah_id').value = tugas.mata_kuliah_id;
            document.getElementById('edit_judul').value = tugas.judul;
            document.getElementById('edit_deskripsi').value = tugas.deskripsi;
            
            // Format datetime-local
            const deadline = new Date(tugas.deadline);
            const formattedDeadline = new Date(deadline.getTime() - deadline.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
            document.getElementById('edit_deadline').value = formattedDeadline;
            
            document.getElementById('edit_max_file_size').value = Math.round(tugas.max_file_size / (1024 * 1024));
            document.getElementById('edit_allowed_extensions').value = tugas.allowed_extensions;
            document.getElementById('edit_is_active').checked = tugas.is_active == 1;
            
            new bootstrap.Modal(document.getElementById('editAssignmentModal')).show();
        }

        function deleteAssignment(id, name) {
            document.getElementById('delete_tugas_id').value = id;
            document.getElementById('deleteAssignmentName').textContent = name;
            new bootstrap.Modal(document.getElementById('deleteModal')).show();
        }

       // Auto-hide alerts after 5 seconds
        document.addEventListener('DOMContentLoaded', function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(function(alert) {
                setTimeout(function() {
                    const bsAlert = new bootstrap.Alert(alert);
                    bsAlert.close();
                }, 5000);
            });
        });

        // Set minimum datetime for deadline inputs
        document.addEventListener('DOMContentLoaded', function() {
            const now = new Date();
            const minDateTime = new Date(now.getTime() - now.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
            
            const deadlineInputs = document.querySelectorAll('input[name="deadline"]');
            deadlineInputs.forEach(function(input) {
                input.min = minDateTime;
            });
        });

        // Form validation
        document.addEventListener('DOMContentLoaded', function() {
            const forms = document.querySelectorAll('form');
            forms.forEach(function(form) {
                form.addEventListener('submit', function(e) {
                    const deadline = form.querySelector('input[name="deadline"]');
                    if (deadline && deadline.value) {
                        const deadlineDate = new Date(deadline.value);
                        const now = new Date();
                        
                        if (deadlineDate <= now) {
                            e.preventDefault();
                            alert('Deadline harus lebih dari waktu sekarang!');
                            return false;
                        }
                    }
                });
            });
        });
    </script>
</body>
</html>
