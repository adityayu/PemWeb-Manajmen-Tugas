<?php
require_once 'includes/auth.php';
requireLogin();

$user = getCurrentUser();
$message = '';
$error = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_profile'])) {
        $full_name = trim($_POST['full_name']);
        $email = trim($_POST['email']);
        $current_password = $_POST['current_password'];
        $new_password = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];
        
        // Validasi input
        if (empty($full_name) || empty($email)) {
            $error = 'Nama lengkap dan email harus diisi!';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Format email tidak valid!';
        } else {
            // Cek apakah email sudah digunakan user lain
            $existing_user = $db->selectOne("SELECT id FROM users WHERE email = ? AND id != ?", [$email, $user['id']]);
            if ($existing_user) {
                $error = 'Email sudah digunakan oleh pengguna lain!';
            } else {
                try {
                    // Update profil
                    $db->query("UPDATE users SET full_name = ?, email = ? WHERE id = ?", 
                              [$full_name, $email, $user['id']]);
                    
                    // Update password jika diisi
                    if (!empty($new_password)) {
                        if (empty($current_password)) {
                            $error = 'Password saat ini harus diisi untuk mengubah password!';
                        } elseif (!password_verify($current_password, $user['password'])) {
                            $error = 'Password saat ini tidak benar!';
                        } elseif (strlen($new_password) < 6) {
                            $error = 'Password baru minimal 6 karakter!';
                        } elseif ($new_password !== $confirm_password) {
                            $error = 'Konfirmasi password tidak cocok!';
                        } else {
                            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                            $db->query("UPDATE users SET password = ? WHERE id = ?", 
                                      [$hashed_password, $user['id']]);
                            $message = 'Profil dan password berhasil diperbarui!';
                        }
                    } else {
                        $message = 'Profil berhasil diperbarui!';
                    }
                    
                    // Refresh user data jika tidak ada error
                    if (empty($error)) {
                        $user = getCurrentUser(true); // Force refresh
                    }
                    
                } catch (Exception $e) {
                    $error = 'Gagal memperbarui profil: ' . $e->getMessage();
                }
            }
        }
    }
}

// Get user statistics
if (isAdmin()) {
    $user_stats = [
        'mata_kuliah' => $db->selectOne("SELECT COUNT(*) as count FROM mata_kuliah WHERE dosen_id = ?", [$user['id']])['count'],
        'tugas_aktif' => $db->selectOne("
            SELECT COUNT(*) as count FROM tugas t 
            JOIN mata_kuliah mk ON t.mata_kuliah_id = mk.id 
            WHERE mk.dosen_id = ? AND t.is_active = 1", [$user['id']])['count'],
        'pengumpulan_bulan_ini' => $db->selectOne("
            SELECT COUNT(*) as count FROM pengumpulan_tugas pt
            JOIN tugas t ON pt.tugas_id = t.id
            JOIN mata_kuliah mk ON t.mata_kuliah_id = mk.id
            WHERE mk.dosen_id = ? AND MONTH(pt.submitted_at) = MONTH(NOW()) AND YEAR(pt.submitted_at) = YEAR(NOW())", [$user['id']])['count']
    ];
} else {
    $user_stats = [
        'mata_kuliah' => $db->selectOne("SELECT COUNT(*) as count FROM enrollments WHERE mahasiswa_id = ?", [$user['id']])['count'],
        'tugas_dikumpulkan' => $db->selectOne("SELECT COUNT(*) as count FROM pengumpulan_tugas WHERE mahasiswa_id = ?", [$user['id']])['count'],
        'rata_rata_nilai' => $db->selectOne("SELECT AVG(nilai) as avg FROM pengumpulan_tugas WHERE mahasiswa_id = ? AND nilai IS NOT NULL", [$user['id']])['avg']
    ];
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profil Saya - Sistem Tugas Mahasiswa</title>
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
        .profile-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px;
            padding: 2rem;
            margin-bottom: 2rem;
            position: relative;
            overflow: hidden;
        }
        .profile-header::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -10%;
            width: 100px;
            height: 100px;
            background: rgba(255,255,255,0.1);
            border-radius: 50%;
            transform: scale(3);
        }
        .profile-avatar {
            width: 100px;
            height: 100px;
            background: rgba(255,255,255,0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2.5rem;
            color: white;
            margin-bottom: 1rem;
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
            padding: 1.5rem;
        }
        .stat-item {
            text-align: center;
            padding: 1.5rem;
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            margin-bottom: 1rem;
        }
        .stat-item i {
            font-size: 2rem;
            margin-bottom: 0.5rem;
        }
        .stat-item.primary i { color: #007bff; }
        .stat-item.success i { color: #28a745; }
        .stat-item.warning i { color: #ffc107; }
        .stat-item.info i { color: #17a2b8; }
        
        .form-floating {
            margin-bottom: 1rem;
        }
        .btn-gradient {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            color: white;
            padding: 12px 30px;
            border-radius: 25px;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        .btn-gradient:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
            color: white;
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
        .password-toggle {
            cursor: pointer;
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            z-index: 10;
        }
        .form-floating {
            position: relative;
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
                        <li><a class="dropdown-item active" href="profile.php"><i class="fas fa-user-edit"></i> Profile</a></li>
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
                            <a class="nav-link" href="mata-kuliah.php">
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
                            <a class="nav-link" href="mata-kuliah.php">
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
                <!-- Profile Header -->
                <div class="profile-header">
                    <div class="row align-items-center">
                        <div class="col-md-8">
                            <div class="d-flex align-items-center">
                                <div class="profile-avatar me-4">
                                    <i class="fas fa-user"></i>
                                </div>
                                <div>
                                    <h2 class="mb-1"><?= htmlspecialchars($user['full_name']) ?></h2>
                                    <p class="mb-1">
                                        <i class="fas fa-envelope me-2"></i><?= htmlspecialchars($user['email']) ?>
                                    </p>
                                    <p class="mb-0">
                                        <?php if (isAdmin()): ?>
                                            <span class="badge bg-light text-dark">
                                                <i class="fas fa-chalkboard-teacher me-1"></i>Dosen
                                            </span>
                                            <?php if ($user['nip']): ?>
                                                <span class="badge bg-light text-dark ms-2">
                                                    NIP: <?= htmlspecialchars($user['nip']) ?>
                                                </span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="badge bg-light text-dark">
                                                <i class="fas fa-user-graduate me-1"></i>Mahasiswa
                                            </span>
                                            <span class="badge bg-light text-dark ms-2">
                                                NIM: <?= htmlspecialchars($user['nim']) ?>
                                            </span>
                                        <?php endif; ?>
                                    </p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4 text-end">
                            <p class="mb-0">
                                <i class="fas fa-calendar-alt me-2"></i>
                                Bergabung: <?= date('d F Y', strtotime($user['created_at'])) ?>
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Statistics -->
                <div class="row mb-4">
                    <?php if (isAdmin()): ?>
                        <div class="col-md-4">
                            <div class="stat-item primary">
                                <i class="fas fa-book"></i>
                                <h4><?= $user_stats['mata_kuliah'] ?></h4>
                                <p class="text-muted mb-0">Mata Kuliah</p>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="stat-item success">
                                <i class="fas fa-tasks"></i>
                                <h4><?= $user_stats['tugas_aktif'] ?></h4>
                                <p class="text-muted mb-0">Tugas Aktif</p>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="stat-item warning">
                                <i class="fas fa-upload"></i>
                                <h4><?= $user_stats['pengumpulan_bulan_ini'] ?></h4>
                                <p class="text-muted mb-0">Pengumpulan Bulan Ini</p>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="col-md-4">
                            <div class="stat-item primary">
                                <i class="fas fa-book"></i>
                                <h4><?= $user_stats['mata_kuliah'] ?></h4>
                                <p class="text-muted mb-0">Mata Kuliah Diambil</p>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="stat-item success">
                                <i class="fas fa-upload"></i>
                                <h4><?= $user_stats['tugas_dikumpulkan'] ?></h4>
                                <p class="text-muted mb-0">Tugas Dikumpulkan</p>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="stat-item info">
                                <i class="fas fa-star"></i>
                                <h4><?= $user_stats['rata_rata_nilai'] ? number_format($user_stats['rata_rata_nilai'], 1) : '0.0' ?></h4>
                                <p class="text-muted mb-0">Rata-rata Nilai</p>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Profile Edit Form -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">
                            <i class="fas fa-edit"></i> Edit Profil
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if ($message): ?>
                            <div class="alert alert-success alert-dismissible fade show" role="alert">
                                <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($message) ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>

                        <?php if ($error): ?>
                            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                <i class="fas fa-exclamation-triangle me-2"></i><?= htmlspecialchars($error) ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>

                        <form method="POST" action="">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-floating">
                                        <input type="text" class="form-control" id="full_name" name="full_name" 
                                               value="<?= htmlspecialchars($user['full_name']) ?>" required>
                                        <label for="full_name">Nama Lengkap</label>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-floating">
                                        <input type="email" class="form-control" id="email" name="email" 
                                               value="<?= htmlspecialchars($user['email']) ?>" required>
                                        <label for="email">Email</label>
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-floating">
                                        <input type="text" class="form-control" id="username" 
                                               value="<?= htmlspecialchars($user['username']) ?>" disabled>
                                        <label for="username">Username</label>
                                        <small class="text-muted">Username tidak dapat diubah</small>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-floating">
                                        <input type="text" class="form-control" id="role" 
                                               value="<?= ucfirst($user['role']) ?>" disabled>
                                        <label for="role">Role</label>
                                        <small class="text-muted">Role tidak dapat diubah</small>
                                    </div>
                                </div>
                            </div>

                            <?php if (!empty($user['nim']) || !empty($user['nip'])): ?>
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="form-floating">
                                            <input type="text" class="form-control" 
                                                   value="<?= htmlspecialchars($user['nim'] ?? $user['nip']) ?>" disabled>
                                            <label><?= isAdmin() ? 'NIP' : 'NIM' ?></label>
                                            <small class="text-muted"><?= isAdmin() ? 'NIP' : 'NIM' ?> tidak dapat diubah</small>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <hr class="my-4">
                            <h6 class="mb-3"><i class="fas fa-lock me-2"></i>Ubah Password (Opsional)</h6>
                            
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="form-floating">
                                        <input type="password" class="form-control" id="current_password" name="current_password">
                                        <label for="current_password">Password Saat Ini</label>
                                        <i class="fas fa-eye password-toggle" onclick="togglePassword('current_password')"></i>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-floating">
                                        <input type="password" class="form-control" id="new_password" name="new_password">
                                        <label for="new_password">Password Baru</label>
                                        <i class="fas fa-eye password-toggle" onclick="togglePassword('new_password')"></i>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-floating">
                                        <input type="password" class="form-control" id="confirm_password" name="confirm_password">
                                        <label for="confirm_password">Konfirmasi Password</label>
                                        <i class="fas fa-eye password-toggle" onclick="togglePassword('confirm_password')"></i>
                                    </div>
                                </div>
                            </div>

                            <div class="text-end mt-4">
                                <button type="button" class="btn btn-outline-secondary me-2" onclick="resetForm()">
                                    <i class="fas fa-undo"></i> Reset
                                </button>
                                <button type="submit" name="update_profile" class="btn-gradient">
                                    <i class="fas fa-save"></i> Simpan Perubahan
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
    <script>
        function togglePassword(fieldId) {
            const field = document.getElementById(fieldId);
            const toggle = field.nextElementSibling.nextElementSibling;
            
            if (field.type === 'password') {
                field.type = 'text';
                toggle.classList.remove('fa-eye');
                toggle.classList.add('fa-eye-slash');
            } else {
                field.type = 'password';
                toggle.classList.remove('fa-eye-slash');
                toggle.classList.add('fa-eye');
            }
        }

        function resetForm() {
            document.querySelector('form').reset();
            // Reset password fields
            document.getElementById('current_password').value = '';
            document.getElementById('new_password').value = '';
            document.getElementById('confirm_password').value = '';
        }

        // Password strength indicator
        document.getElementById('new_password').addEventListener('input', function() {
            const password = this.value;
            const strengthMeter = document.getElementById('password-strength');
            
            if (password.length === 0) {
                if (strengthMeter) strengthMeter.remove();
                return;
            }
            
            let strength = 0;
            if (password.length >= 6) strength++;
            if (password.match(/[a-z]/)) strength++;
            if (password.match(/[A-Z]/)) strength++;
            if (password.match(/[0-9]/)) strength++;
            if (password.match(/[^a-zA-Z0-9]/)) strength++;
            
            const colors = ['danger', 'warning', 'info', 'success', 'success'];
            const texts = ['Sangat Lemah', 'Lemah', 'Sedang', 'Kuat', 'Sangat Kuat'];
            
            let meter = document.getElementById('password-strength');
            if (!meter) {
                meter = document.createElement('div');
                meter.id = 'password-strength';
                meter.className = 'mt-2';
                this.parentNode.appendChild(meter);
            }
            
            meter.innerHTML = `
                <div class="progress" style="height: 5px;">
                    <div class="progress-bar bg-${colors[strength-1]}" style="width: ${strength * 20}%"></div>
                </div>
                <small class="text-${colors[strength-1]}">${texts[strength-1]}</small>
            `;
        });

        // Confirm password match
        document.getElementById('confirm_password').addEventListener('input', function() {
            const newPassword = document.getElementById('new_password').value;
            const confirmPassword = this.value;
            
            if (confirmPassword && newPassword !== confirmPassword) {
                this.setCustomValidity('Password tidak cocok');
                this.classList.add('is-invalid');
            } else {
                this.setCustomValidity('');
                this.classList.remove('is-invalid');
            }
        });

        // Highlight navigation
        document.addEventListener('DOMContentLoaded', function() {
            const currentPath = window.location.pathname;
            const navLinks = document.querySelectorAll('.nav-link');
            
            navLinks.forEach(link => {
                if (link.getAttribute('href') === currentPath.split('/').pop()) {
                    link.classList.add('active');
                } else {
                    link.classList.remove('active');
                }
            });
        });
    </script>
</body>
</html>
