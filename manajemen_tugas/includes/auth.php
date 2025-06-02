<?php
// includes/auth.php - Sistem Autentikasi dan Session Management
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require_once __DIR__ . '/../config/database.php';

class Auth {
    private $db;

    public function __construct() {
        global $db;
        $this->db = $db;
    }

    // Login user
    public function login($username, $password) {
        try {
            $user = $this->db->selectOne(
                "SELECT * FROM users WHERE username = ? OR email = ?", 
                [$username, $username]
            );

            if ($user && password_verify($password, $user['password'])) {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['email'] = $user['email'];
                
                if ($user['role'] == 'mahasiswa') {
                    $_SESSION['nim'] = $user['nim'];
                } else {
                    $_SESSION['nip'] = $user['nip'];
                }

                return ['success' => true, 'user' => $user];
            } else {
                return ['success' => false, 'message' => 'Username atau password salah'];
            }
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    }

    // Register user baru (hanya untuk mahasiswa, admin dibuat manual)
    public function register($data) {
        try {
            // Validasi input
            if (empty($data['username']) || empty($data['email']) || empty($data['password']) || 
                empty($data['full_name']) || empty($data['nim'])) {
                return ['success' => false, 'message' => 'Semua field harus diisi'];
            }

            // Cek apakah username atau email sudah ada
            $existing = $this->db->selectOne(
                "SELECT id FROM users WHERE username = ? OR email = ? OR nim = ?", 
                [$data['username'], $data['email'], $data['nim']]
            );

            if ($existing) {
                return ['success' => false, 'message' => 'Username, email, atau NIM sudah terdaftar'];
            }

            // Hash password
            $hashedPassword = password_hash($data['password'], PASSWORD_DEFAULT);

            // Insert user baru
            $this->db->execute(
                "INSERT INTO users (username, email, password, full_name, role, nim) VALUES (?, ?, ?, ?, 'mahasiswa', ?)",
                [$data['username'], $data['email'], $hashedPassword, $data['full_name'], $data['nim']]
            );

            return ['success' => true, 'message' => 'Registrasi berhasil'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    }

    // Logout
    public function logout() {
        session_destroy();
        header('Location: login.php');
        exit();
    }

    // Cek apakah user sudah login
    public function isLoggedIn() {
        return isset($_SESSION['user_id']);
    }

    // Cek role user
    public function getRole() {
        return isset($_SESSION['role']) ? $_SESSION['role'] : null;
    }

    // Cek apakah user adalah admin
    public function isAdmin() {
        return $this->getRole() === 'admin';
    }

    // Cek apakah user adalah mahasiswa
    public function isMahasiswa() {
        return $this->getRole() === 'mahasiswa';
    }

    // Get current user data
    public function getCurrentUser() {
        if (!$this->isLoggedIn()) {
            return null;
        }

        return [
            'id' => $_SESSION['user_id'],
            'username' => $_SESSION['username'],
            'full_name' => $_SESSION['full_name'],
            'role' => $_SESSION['role'],
            'email' => $_SESSION['email'],
            'nim' => isset($_SESSION['nim']) ? $_SESSION['nim'] : null,
            'nip' => isset($_SESSION['nip']) ? $_SESSION['nip'] : null
        ];
    }

    // Require login - redirect jika belum login
    public function requireLogin() {
        if (!$this->isLoggedIn()) {
            header('Location: login.php');
            exit();
        }
    }

    // Require admin - redirect jika bukan admin
    public function requireAdmin() {
        $this->requireLogin();
        if (!$this->isAdmin()) {
            header('Location: dashboard.php');
            exit();
        }
    }

    // Generate CSRF token
    public function generateCSRFToken() {
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    // Validasi CSRF token
    public function validateCSRFToken($token) {
        return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
    }
}

// Global auth instance
$auth = new Auth();

// Helper functions
function isLoggedIn() {
    global $auth;
    return $auth->isLoggedIn();
}

function isAdmin() {
    global $auth;
    return $auth->isAdmin();
}

function isMahasiswa() {
    global $auth;
    return $auth->isMahasiswa();
}

function getCurrentUser() {
    global $auth;
    return $auth->getCurrentUser();
}

function requireLogin() {
    global $auth;
    $auth->requireLogin();
}

function requireAdmin() {
    global $auth;
    $auth->requireAdmin();
}
?>
