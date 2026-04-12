<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// ── DB Connection ──
$host     = "dpg-d77lipqdbo4c73arvtv0-a.singapore-postgres.render.com";
$port     = "5432";
$dbname   = "unisphere_h4rb";
$user     = "root";
$password = "eA4dn3XSdHcuo99MljBnLq1AOnZxpIUY";

try {
    $conn = new PDO(
        "pgsql:host=$host;port=$port;dbname=$dbname;sslmode=require",
        $user,
        $password
    );
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die(json_encode(["success" => false, "message" => $e->getMessage()]));
}

// ── Auto-create tables ──
try {
    $conn->exec("
        CREATE TABLE IF NOT EXISTS mcq_tests (
            id          SERIAL PRIMARY KEY,
            title       VARCHAR(255) NOT NULL,
            description TEXT,
            topic       VARCHAR(255),
            total_questions INT DEFAULT 0,
            time_per_question INT DEFAULT 60,
            created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS mcq_questions (
            id          SERIAL PRIMARY KEY,
            test_id     INT REFERENCES mcq_tests(id) ON DELETE CASCADE,
            question_no INT,
            topic       VARCHAR(255),
            question    TEXT NOT NULL,
            options     JSONB NOT NULL,
            correct_answer VARCHAR(255) NOT NULL,
            explanation TEXT,
            created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS mcq_results (
            id          SERIAL PRIMARY KEY,
            test_id     INT REFERENCES mcq_tests(id) ON DELETE CASCADE,
            score       INT,
            total       INT,
            correct     INT,
            wrong       INT,
            skipped     INT,
            time_taken  INT,
            answers     JSONB,
            taken_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );
    ");
} catch (PDOException $e) {
    die(json_encode(["success" => false, "message" => "Schema error: " . $e->getMessage()]));
}

// ── Router ──
$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($action)) {
    $body = json_decode(file_get_contents("php://input"), true);
    $action = $body['action'] ?? '';
} else {
    $body = json_decode(file_get_contents("php://input"), true) ?? [];
}

switch ($action) {

    // ── List all tests ──
    case 'list_tests':
        $stmt = $conn->query("
            SELECT t.*, COUNT(q.id) as q_count
            FROM mcq_tests t
            LEFT JOIN mcq_questions q ON q.test_id = t.id
            GROUP BY t.id
            ORDER BY t.created_at DESC
        ");
        echo json_encode(["success" => true, "tests" => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        break;

    // ── Upload / create test ──
    case 'upload_test':
        $title       = $body['title'] ?? 'Untitled Test';
        $description = $body['description'] ?? '';
        $topic       = $body['topic'] ?? '';
        $tpq         = intval($body['time_per_question'] ?? 60);
        $questions   = $body['questions'] ?? [];

        if (empty($questions)) {
            echo json_encode(["success" => false, "message" => "No questions provided."]);
            break;
        }

        try {
            $conn->beginTransaction();

            $stmt = $conn->prepare("
                INSERT INTO mcq_tests (title, description, topic, total_questions, time_per_question)
                VALUES (:title, :desc, :topic, :total, :tpq)
                RETURNING id
            ");
            $stmt->execute([
                ':title' => $title,
                ':desc'  => $description,
                ':topic' => $topic,
                ':total' => count($questions),
                ':tpq'   => $tpq
            ]);
            $testId = $stmt->fetchColumn();

            $qstmt = $conn->prepare("
                INSERT INTO mcq_questions (test_id, question_no, topic, question, options, correct_answer, explanation)
                VALUES (:tid, :qno, :topic, :q, :opts, :ans, :expl)
            ");

            foreach ($questions as $i => $q) {
                $qstmt->execute([
                    ':tid'   => $testId,
                    ':qno'   => $i + 1,
                    ':topic' => $q['topic'] ?? $topic,
                    ':q'     => $q['question'],
                    ':opts'  => json_encode($q['options']),
                    ':ans'   => $q['correct_answer'],
                    ':expl'  => $q['explanation'] ?? ''
                ]);
            }

            $conn->commit();
            echo json_encode(["success" => true, "test_id" => $testId, "message" => "Test uploaded successfully."]);
        } catch (PDOException $e) {
            $conn->rollBack();
            echo json_encode(["success" => false, "message" => $e->getMessage()]);
        }
        break;

    // ── Get test + questions ──
    case 'get_test':
        $testId = intval($body['test_id'] ?? $_GET['test_id'] ?? 0);
        if (!$testId) { echo json_encode(["success" => false, "message" => "Missing test_id"]); break; }

        $tstmt = $conn->prepare("SELECT * FROM mcq_tests WHERE id = :id");
        $tstmt->execute([':id' => $testId]);
        $test = $tstmt->fetch(PDO::FETCH_ASSOC);

        if (!$test) { echo json_encode(["success" => false, "message" => "Test not found"]); break; }

        $qstmt = $conn->prepare("SELECT * FROM mcq_questions WHERE test_id = :id ORDER BY question_no ASC");
        $qstmt->execute([':id' => $testId]);
        $questions = $qstmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($questions as &$q) {
            $q['options'] = json_decode($q['options'], true);
        }

        echo json_encode(["success" => true, "test" => $test, "questions" => $questions]);
        break;

    // ── Delete test ──
    case 'delete_test':
        $testId = intval($body['test_id'] ?? 0);
        if (!$testId) { echo json_encode(["success" => false, "message" => "Missing test_id"]); break; }

        $stmt = $conn->prepare("DELETE FROM mcq_tests WHERE id = :id");
        $stmt->execute([':id' => $testId]);
        echo json_encode(["success" => true, "message" => "Test deleted."]);
        break;

    // ── Save result ──
    case 'save_result':
        $testId    = intval($body['test_id'] ?? 0);
        $score     = intval($body['score'] ?? 0);
        $total     = intval($body['total'] ?? 0);
        $correct   = intval($body['correct'] ?? 0);
        $wrong     = intval($body['wrong'] ?? 0);
        $skipped   = intval($body['skipped'] ?? 0);
        $timeTaken = intval($body['time_taken'] ?? 0);
        $answers   = $body['answers'] ?? [];

        $stmt = $conn->prepare("
            INSERT INTO mcq_results (test_id, score, total, correct, wrong, skipped, time_taken, answers)
            VALUES (:tid, :score, :total, :correct, :wrong, :skipped, :time, :answers)
            RETURNING id
        ");
        $stmt->execute([
            ':tid'     => $testId,
            ':score'   => $score,
            ':total'   => $total,
            ':correct' => $correct,
            ':wrong'   => $wrong,
            ':skipped' => $skipped,
            ':time'    => $timeTaken,
            ':answers' => json_encode($answers)
        ]);
        echo json_encode(["success" => true, "result_id" => $stmt->fetchColumn()]);
        break;

    // ── Get test history ──
    case 'get_history':
        $testId = intval($_GET['test_id'] ?? 0);
        if ($testId) {
            $stmt = $conn->prepare("SELECT * FROM mcq_results WHERE test_id = :id ORDER BY taken_at DESC LIMIT 10");
            $stmt->execute([':id' => $testId]);
        } else {
            $stmt = $conn->query("SELECT r.*, t.title FROM mcq_results r JOIN mcq_tests t ON t.id = r.test_id ORDER BY r.taken_at DESC LIMIT 20");
        }
        echo json_encode(["success" => true, "results" => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        break;

    default:
        echo json_encode(["success" => false, "message" => "Unknown action: $action"]);
}
?>