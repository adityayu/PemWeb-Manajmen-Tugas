<?php
// config/database.php - Konfigurasi Database Connection

class Database {
    private $host = "localhost";
    private $database = "sistem_tugas_mahasiswa";
    private $username = "root";
    private $password = "";
    private $connection;

    public function __construct() {
        try {
            $this->connection = new PDO(
                "mysql:host={$this->host};dbname={$this->database};charset=utf8mb4",
                $this->username, 
                $this->password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false
                ]
            );
        } catch(PDOException $e) {
            die("Connection failed: " . $e->getMessage());
        }
    }

    public function getConnection() {
        return $this->connection;
    }

    // Method untuk prepared statement dengan parameter
    public function query($sql, $params = []) {
        try {
            $stmt = $this->connection->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch(PDOException $e) {
            throw new Exception("Query failed: " . $e->getMessage());
        }
    }

    // Method untuk SELECT query
    public function select($sql, $params = []) {
        $stmt = $this->query($sql, $params);
        return $stmt->fetchAll();
    }

    // Method untuk SELECT single row
    public function selectOne($sql, $params = []) {
        $stmt = $this->query($sql, $params);
        return $stmt->fetch();
    }

    // Method untuk INSERT, UPDATE, DELETE
    public function execute($sql, $params = []) {
        $stmt = $this->query($sql, $params);
        return $stmt->rowCount();
    }

    // Method untuk mendapatkan last insert ID
    public function lastInsertId() {
        return $this->connection->lastInsertId();
    }

    // Method untuk begin transaction
    public function beginTransaction() {
        return $this->connection->beginTransaction();
    }

    // Method untuk commit transaction
    public function commit() {
        return $this->connection->commit();
    }

    // Method untuk rollback transaction
    public function rollback() {
        return $this->connection->rollback();
    }
}

// Global database instance
$db = new Database();
?>
