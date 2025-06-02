<?php
require_once 'includes/auth.php';
requireLogin();
requireAdmin(); // Hanya admin/dosen yang bisa akses
$user = getCurrentUser();

// Handle actions
$message = '';
$message_type = '';

if ($_POST) {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'enroll':
                $mahasiswa_id = $_POST['mahasiswa_id'];
                $mata_kuliah_id = $_POST['mata_kuliah_id'];
                
                // Check if already enrolled
                $existing = $db->selectOne("SELECT id FROM enrollments WHERE mahasiswa_id = ? AND mata_kuliah_id = ?", 
                    [$mahasiswa_id, $mata_kuliah_id]);
                
                if (!$existing) {
                    $result = $db->insert("INSERT INTO enrollments (mahasiswa_id, mata_kuliah_id) VALUES (?, ?)", 
                        [$mahasiswa_id, $mata_kuliah_id]);
                    
                    if ($result) {
                        $message = 'Mahasiswa berhasil didaftarkan ke mata kuliah!';
                        $message_type = 'success';
                    } else {
                        $message = 'Gagal mendaftarkan mahasiswa!';
                        $message_type = 'danger';
                    }
                } else {
                    $message = 'Mahasiswa sudah terdaftar di mata kuliah tersebut!';
                    $message_type = 'warning';
                }
                break;
                
            case 'unenroll':
                $enrollment_id = $_POST['enrollment_id'];
                $result = $db->delete("DELETE FROM enrollments WHERE id = ?", [$enrollment_id]);
                
                if ($result) {
                    $message = 'Mahasiswa berhasil dikeluarkan dari mata kuliah!';
                    $message_type = 'success';
                } else {
                    $message = 'Gagal mengeluarkan mahasiswa!';
                    $message_type = 'danger';
                }
                break;
        }
    }
}

// Get filter parameters
$mata_kuliah_filter = isset($_GET['mata_kuliah']) ? $_GET['mata_kuliah'] : '';
$search = isset($_GET['search']) ? $_GET['search'] : '';

// Get mata kuliah yang diampu dosen ini
$mata_kuliah_list = $db->select("SELECT id, kode_mk, nama_mk FROM mata_kuliah WHERE dosen_id = ? ORDER BY nama_mk", [$user['id']]);

// Build query for enrolled students
$where_conditions = ["mk.dosen_id = ?"];
$params = [$user['id']];

if ($mata_kuliah_filter) {
    $where_conditions[] = "mk.id = ?";
    $params[] = $mata_kuliah_filter;
}

if ($search) {
    $where_conditions[] = "(u.full_name LIKE ? OR u.nim LIKE ? OR u.email LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
}

$where_clause = implode(" AND ", $where_conditions);

// Get enrolled students with their enrollment info
$enrolled_students = $db->select("
    SELECT 
        e.id as enrollment_id,
        u.id as mahasiswa_id,
        u.full_name,
        u.nim,
        u.email,
        mk.id as mata_kuliah_id,
        mk.kode_mk,
        mk.nama_mk,
        e.enrolled_at,
        -- Statistik tugas mahasiswa
        COUNT(DISTINCT t.id) as total_tugas,
        COUNT(DISTINCT pt.id) as tugas_dikumpulkan,
        COUNT(DISTINCT CASE WHEN pt.nilai IS NOT NULL THEN pt.id END) as tugas_dinilai,
        AVG(pt.nilai) as rata_rata_nilai
    FROM enrollments e
    JOIN users u ON e.mahasiswa_id = u.id
    JOIN mata_kuliah mk ON e.mata_kuliah_id = mk.id
    LEFT JOIN tugas t ON mk.id = t.mata_kuliah_id AND t.is_active = 1
    LEFT JOIN pengumpulan_tugas pt ON (t.id = pt.tugas_id AND u.id = pt.mahasiswa_id)
    WHERE $where_clause
    GROUP BY e.id, u.id, mk.id
    ORDER BY mk.nama_mk, u.full_name
", $params);

// Get all students for enrollment modal (students not yet enrolled in any of teacher's courses)
$all_students = $db->select("SELECT id, full_name, nim, email FROM users WHERE role = 'mahasiswa' ORDER BY full_name");

// Statistics
$total_enrollments = count($enrolled_students);
$unique_students = count(array_unique(array_column($enrolled_students, 'mahasiswa_id')));
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kelola Mahasiswa - Sistem Tugas Mahasiswa</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/datatables/1.10.21/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; }
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
        .stat-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            transition: transform 0.3s ease;
            border-left: 4px solid;
        }
        .stat-card:hover { transform: translateY(-5px); }
        .stat-card.primary { border-left-color: #007bff; }
        .stat-card.success { border-left-color: #28a745; }
        .stat-card.warning { border-left-color: #ffc107; }
        .stat-card.info { border-left-color: #17a2b8; }
        
        .student-avatar {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
        }
        .progress-small {
            height: 8px;
        }
        .modal-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
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
                        <a class="nav-link" href="penilaian.php">
                            <i class="fas fa-star"></i> Penilaian
                        </a>
                        <a class="nav-link active" href="mahasiswa.php">
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
                        <h2><i class="fas fa-users"></i> Kelola Mahasiswa</h2>
                        <p class="text-muted">Kelola mahasiswa yang terdaftar dalam mata kuliah Anda</p>
                    </div>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#enrollModal">
                        <i class="fas fa-user-plus"></i> Daftarkan Mahasiswa
                    </button>
                </div>

                <!-- Alert Messages -->
                <?php if ($message): ?>
                    <div class="alert alert-<?= $message_type ?> alert-dismissible fade show" role="alert">
                        <?= $message ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Statistics Cards -->
                <div class="row mb-4">
                    <div class="col-md-3 mb-3">
                        <div class="stat-card primary p-3">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <h6 class="text-muted">Total Pendaftaran</h6>
                                    <h3 class="mb-0"><?= $total_enrollments ?></h3>
                                </div>
                                <i class="fas fa-user-plus fa-2x text-primary"></i>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <div class="stat-card success p-3">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <h6 class="text-muted">Mahasiswa Unik</h6>
                                    <h3 class="mb-0"><?= $unique_students ?></h3>
                                </div>
                                <i class="fas fa-users fa-2x text-success"></i>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <div class="stat-card warning p-3">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <h6 class="text-muted">Mata Kuliah</h6>
                                    <h3 class="mb-0"><?= count($mata_kuliah_list) ?></h3>
                                </div>
                                <i class="fas fa-book fa-2x text-warning"></i>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <div class="stat-card info p-3">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <h6 class="text-muted">Rata-rata per MK</h6>
                                    <h3 class="mb-0"><?= count($mata_kuliah_list) > 0 ? round($total_enrollments / count($mata_kuliah_list), 1) : 0 ?></h3>
                                </div>
                                <i class="fas fa-chart-line fa-2x text-info"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filter Section -->
                <div class="card mb-4">
                    <div class="card-body">
                        <form method="GET" class="row align-items-end">
                            <div class="col-md-4">
                                <label class="form-label">Filter Mata Kuliah</label>
                                <select name="mata_kuliah" class="form-select">
                                    <option value="">Semua Mata Kuliah</option>
                                    <?php foreach ($mata_kuliah_list as $mk): ?>
                                        <option value="<?= $mk['id'] ?>" <?= $mata_kuliah_filter == $mk['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($mk['kode_mk'] . ' - ' . $mk['nama_mk']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Cari Mahasiswa</label>
                                <input type="text" name="search" class="form-control" placeholder="Nama, NIM, atau Email..." value="<?= htmlspecialchars($search) ?>">
                            </div>
                            <div class="col-md-4">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-search"></i> Filter
                                </button>
                                <a href="mahasiswa.php" class="btn btn-outline-secondary">
                                    <i class="fas fa-times"></i> Reset
                                </a>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Students Table -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-list"></i> Daftar Mahasiswa</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($enrolled_students)): ?>
                            <div class="text-center py-5">
                                <i class="fas fa-users fa-3x text-muted mb-3"></i>
                                <h5>Belum ada mahasiswa terdaftar</h5>
                                <p class="text-muted">Klik tombol "Daftarkan Mahasiswa" untuk menambahkan mahasiswa ke mata kuliah Anda.</p>
                                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#enrollModal">
                                    <i class="fas fa-user-plus"></i> Daftarkan Mahasiswa
                                </button>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover" id="studentsTable">
                                    <thead>
                                        <tr>
                                            <th>Mahasiswa</th>
                                            <th>Mata Kuliah</th>
                                            <th>Terdaftar</th>
                                            <th>Progress Tugas</th>
                                            <th>Rata-rata Nilai</th>
                                            <th>Aksi</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($enrolled_students as $student): ?>
                                            <tr>
                                                <td>
                                                    <div class="d-flex align-items-center">
                                                        <div class="student-avatar me-3">
                                                            <?= strtoupper(substr($student['full_name'], 0, 2)) ?>
                                                        </div>
                                                        <div>
                                                            <h6 class="mb-0"><?= htmlspecialchars($student['full_name']) ?></h6>
                                                            <small class="text-muted">NIM: <?= htmlspecialchars($student['nim']) ?></small><br>
                                                            <small class="text-muted"><?= htmlspecialchars($student['email']) ?></small>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td>
                                                    <strong><?= htmlspecialchars($student['kode_mk']) ?></strong><br>
                                                    <small><?= htmlspecialchars($student['nama_mk']) ?></small>
                                                </td>
                                                <td>
                                                    <small class="text-muted">
                                                        <?= date('d/m/Y', strtotime($student['enrolled_at'])) ?>
                                                    </small>
                                                </td>
                                                <td>
                                                    <?php
                                                    $progress_percentage = $student['total_tugas'] > 0 ? 
                                                        ($student['tugas_dikumpulkan'] / $student['total_tugas']) * 100 : 0;
                                                    ?>
                                                    <div class="mb-1">
                                                        <small><?= $student['tugas_dikumpulkan'] ?>/<?= $student['total_tugas'] ?> tugas</small>
                                                    </div>
                                                    <div class="progress progress-small">
                                                        <div class="progress-bar" style="width: <?= $progress_percentage ?>%"></div>
                                                    </div>
                                                    <small class="text-muted"><?= round($progress_percentage, 1) ?>%</small>
                                                </td>
                                                <td>
                                                    <?php if ($student['rata_rata_nilai']): ?>
                                                        <span class="badge bg-primary fs-6">
                                                            <?= number_format($student['rata_rata_nilai'], 1) ?>
                                                        </span>
                                                        <br><small class="text-muted"><?= $student['tugas_dinilai'] ?> dinilai</small>
                                                    <?php else: ?>
                                                        <span class="text-muted">Belum ada nilai</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="btn-group btn-group-sm">
                                                        <a href="mahasiswa-detail.php?id=<?= $student['mahasiswa_id'] ?>&mk=<?= $student['mata_kuliah_id'] ?>" 
                                                           class="btn btn-outline-primary" title="Lihat Detail">
                                                            <i class="fas fa-eye"></i>
                                                        </a>
                                                        <button class="btn btn-outline-danger" 
                                                                onclick="confirmUnenroll(<?= $student['enrollment_id'] ?>, '<?= htmlspecialchars($student['full_name']) ?>', '<?= htmlspecialchars($student['nama_mk']) ?>')"
                                                                title="Keluarkan dari Mata Kuliah">
                                                            <i class="fas fa-user-minus"></i>
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Enroll Student Modal -->
    <div class="modal fade" id="enrollModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="fas fa-user-plus"></i> Daftarkan Mahasiswa</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="action" value="enroll">
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Pilih Mahasiswa *</label>
                                <select name="mahasiswa_id" class="form-select" required>
                                    <option value="">-- Pilih Mahasiswa --</option>
                                    <?php foreach ($all_students as $student): ?>
                                        <option value="<?= $student['id'] ?>">
                                            <?= htmlspecialchars($student['full_name']) ?> (<?= htmlspecialchars($student['nim']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Pilih Mata Kuliah *</label>
                                <select name="mata_kuliah_id" class="form-select" required>
                                    <option value="">-- Pilih Mata Kuliah --</option>
                                    <?php foreach ($mata_kuliah_list as $mk): ?>
                                        <option value="<?= $mk['id'] ?>">
                                            <?= htmlspecialchars($mk['kode_mk'] . ' - ' . $mk['nama_mk']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i>
                            <strong>Catatan:</strong> Pastikan mahasiswa belum terdaftar di mata kuliah yang dipilih.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Daftarkan
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Unenroll Confirmation Modal -->
    <div class="modal fade" id="unenrollModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header bg-danger text-white">
                        <h5 class="modal-title"><i class="fas fa-user-minus"></i> Konfirmasi Pengeluaran</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="action" value="unenroll">
                        <input type="hidden" name="enrollment_id" id="unenroll_id">
                        
                        <div class="text-center">
                            <i class="fas fa-exclamation-triangle fa-3x text-warning mb-3"></i>
                            <h5>Apakah Anda yakin?</h5>
                            <p class="mb-0">Mahasiswa <strong id="student_name"></strong> akan dikeluarkan dari mata kuliah <strong id="course_name"></strong>.</p>
                        </div>
                        
                        <div class="alert alert-warning mt-3">
                            <i class="fas fa-exclamation-triangle"></i>
                            <strong>Peringatan:</strong> Tindakan ini akan menghapus semua data terkait mahasiswa dalam mata kuliah ini, termasuk pengumpulan tugas dan nilai.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-danger">
                            <i class="fas fa-user-minus"></i> Ya, Keluarkan
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/datatables/1.10.21/js/jquery.dataTables.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/datatables/1.10.21/js/dataTables.bootstrap5.min.js"></script>
    
    <script>
        // Initialize DataTable
        $(document).ready(function() {
            $('#studentsTable').DataTable({
                pageLength: 25,
                order: [[0, 'asc']],
                language: {
                    url: 'https://cdn.datatables.net/plug-ins/1.10.21/i18n/Indonesian.json'
                }
            });
        });

        // Confirm unenroll function
        function confirmUnenroll(enrollmentId, studentName, courseName) {
            document.getElementById('unenroll_id').value = enrollmentId;
            document.getElementById('student_name').textContent = studentName;
            document.getElementById('course_name').textContent = courseName;
            
            var modal = new bootstrap.Modal(document.getElementById('unenrollModal'));
            modal.show();
        }

        // Auto-hide alerts
        setTimeout(function() {
            var alerts = document.querySelectorAll('.alert');
            alerts.forEach(function(alert) {
                var bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            });
        }, 5000);
    </script>
</body>
</html>
