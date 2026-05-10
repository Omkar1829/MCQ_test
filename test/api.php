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
$host     = "db.ienakmntjzlpkpaxefej.supabase.co";
$port     = "5432";
$dbname   = "postgres";
$user     = "root";
$password = "TheBestPasswordEver";

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

function normalize_question($question, $fallbackTopic = '') {
    if (!is_array($question)) {
        return null;
    }

    $questionText = trim((string)($question['question'] ?? ''));
    $options = $question['options'] ?? [];
    $correct = $question['correct_answer'] ?? null;
    $explanation = trim((string)($question['explanation'] ?? ''));
    $topic = trim((string)($question['topic'] ?? $fallbackTopic));

    if ($questionText === '' || !is_array($options) || count($options) < 2) {
        return null;
    }

    $normalizedOptions = [];
    foreach ($options as $option) {
        $value = trim((string)$option);
        if ($value !== '') {
            $normalizedOptions[] = $value;
        }
    }

    if (count($normalizedOptions) < 2) {
        return null;
    }

    if (is_int($correct) || ctype_digit((string)$correct)) {
        $index = intval($correct);
        if ($index >= 0 && $index < count($normalizedOptions)) {
            $correct = $normalizedOptions[$index];
        }
    } elseif (is_string($correct)) {
        $candidate = trim($correct);
        $letters = ['A', 'B', 'C', 'D', 'E', 'F'];
        if (in_array(strtoupper($candidate), $letters, true)) {
            $letterIndex = array_search(strtoupper($candidate), $letters, true);
            if ($letterIndex !== false && isset($normalizedOptions[$letterIndex])) {
                $correct = $normalizedOptions[$letterIndex];
            } else {
                $correct = $candidate;
            }
        } else {
            $correct = $candidate;
        }
    }

    $correct = trim((string)$correct);
    if ($correct === '' || !in_array($correct, $normalizedOptions, true)) {
        return null;
    }

    return [
        'topic' => $topic,
        'question' => $questionText,
        'options' => $normalizedOptions,
        'correct_answer' => $correct,
        'explanation' => $explanation
    ];
}

function normalize_upload_payload($body) {
    $payload = is_array($body) ? $body : [];
    $questions = $payload['questions'] ?? $payload['items'] ?? $payload['mcqs'] ?? null;

    if ($questions === null && array_is_list($payload)) {
        $questions = $payload;
        $payload = [];
    }

    if (!is_array($questions)) {
        $questions = [];
    }

    $topic = trim((string)($payload['topic'] ?? $payload['subject'] ?? ''));
    $title = trim((string)($payload['title'] ?? $payload['name'] ?? $payload['subject'] ?? 'Untitled Test'));
    $description = trim((string)($payload['description'] ?? $payload['summary'] ?? ''));
    $timePerQuestion = intval($payload['time_per_question'] ?? $payload['timePerQuestion'] ?? 60);
    if ($timePerQuestion < 0) {
        $timePerQuestion = 60;
    }

    $normalizedQuestions = [];
    foreach ($questions as $question) {
        $normalized = normalize_question($question, $topic);
        if ($normalized !== null) {
            $normalizedQuestions[] = $normalized;
        }
    }

    return [
        'title' => $title,
        'description' => $description,
        'topic' => $topic,
        'time_per_question' => $timePerQuestion,
        'questions' => $normalizedQuestions
    ];
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
        $normalized  = normalize_upload_payload($body);
        $title       = $normalized['title'];
        $description = $normalized['description'];
        $topic       = $normalized['topic'];
        $tpq         = $normalized['time_per_question'];
        $questions   = $normalized['questions'];

        if (empty($questions)) {
            echo json_encode(["success" => false, "message" => "No valid questions provided."]);
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
