<?php
session_start();
require_once __DIR__ . '/db.php';

mysqli_set_charset($conn, 'utf8mb4');

if (empty($_SESSION['placementhub_csrf'])) {
    $_SESSION['placementhub_csrf'] = bin2hex(random_bytes(32));
}

function h($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function redirectStudents($message) {
    header('Location: students.php?message=' . urlencode($message));
    exit;
}

$message = $_GET['message'] ?? '';
$error = '';
$editing = false;

$form = [
    'student_id' => '',
    'name' => '',
    'email' => '',
    'phone' => '',
    'cgpa' => '',
    'graduation_year' => date('Y') + 1,
    'department_id' => ''
];

// Handle add, edit and delete requests.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';

    if (!hash_equals($_SESSION['placementhub_csrf'], $token)) {
        http_response_code(403);
        exit('Invalid request token. Refresh the page and try again.');
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'delete') {
        $id = filter_var(
            $_POST['student_id'] ?? '',
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );

        if (!$id) {
            redirectStudents('invalid_id');
        }

        $stmt = mysqli_prepare(
            $conn,
            'DELETE FROM Student WHERE student_id = ?'
        );
        mysqli_stmt_bind_param($stmt, 'i', $id);
        $success = mysqli_stmt_execute($stmt);
        $affected = mysqli_stmt_affected_rows($stmt);
        mysqli_stmt_close($stmt);

        if ($success && $affected > 0) {
            redirectStudents('deleted');
        }

        if ($success) {
            redirectStudents('not_found');
        }

        // A student with applications may be protected by foreign keys.
        redirectStudents('delete_blocked');
    }

    if ($action === 'add' || $action === 'update') {
        $form['student_id'] = trim($_POST['student_id'] ?? '');
        $form['name'] = trim($_POST['name'] ?? '');
        $form['email'] = trim($_POST['email'] ?? '');
        $form['phone'] = trim($_POST['phone'] ?? '');
        $form['cgpa'] = trim($_POST['cgpa'] ?? '');
        $form['graduation_year'] = trim($_POST['graduation_year'] ?? '');
        $form['department_id'] = trim($_POST['department_id'] ?? '');

        $id = filter_var(
            $form['student_id'],
            FILTER_VALIDATE_INT,
            ['options' => ['options' => ['min_range' => 1]]
            ]
        );

        // Validate the student ID separately for updates.
        if ($action === 'update') {
            $id = filter_var($form['student_id'], FILTER_VALIDATE_INT);
            if (!$id || $id < 1) {
                $error = 'Invalid student ID.';
            }
        }

        if ($form['name'] === '' || mb_strlen($form['name']) > 100) {
            $error = 'Name is required and must be at most 100 characters.';
        } elseif (
            $form['email'] !== '' &&
            (!filter_var($form['email'], FILTER_VALIDATE_EMAIL) ||
             strlen($form['email']) > 100)
        ) {
            $error = 'Enter a valid email address (maximum 100 characters).';
        } elseif (
            $form['phone'] !== '' &&
            (!preg_match('/^[0-9+\-\s()]{7,15}$/', $form['phone']))
        ) {
            $error = 'Enter a valid phone number (7–15 characters).';
        } elseif (
            $form['cgpa'] === '' ||
            !is_numeric($form['cgpa']) ||
            (float)$form['cgpa'] < 0 ||
            (float)$form['cgpa'] > 10
        ) {
            $error = 'CGPA must be between 0 and 10.';
        } elseif (
            !ctype_digit($form['graduation_year']) ||
            (int)$form['graduation_year'] < 2000 ||
            (int)$form['graduation_year'] > 2100
        ) {
            $error = 'Enter a valid graduation year between 2000 and 2100.';
        } elseif (
            !ctype_digit($form['department_id']) ||
            (int)$form['department_id'] < 1
        ) {
            $error = 'Please select a department.';
        } elseif ($action === 'update' && (!$id || $id < 1)) {
            $error = 'Invalid student ID.';
        }

        // Confirm that the selected department exists.
        if ($error === '') {
            $departmentId = (int)$form['department_id'];
            $check = mysqli_prepare(
                $conn,
                'SELECT department_id FROM Department WHERE department_id = ?'
            );
            mysqli_stmt_bind_param($check, 'i', $departmentId);
            mysqli_stmt_execute($check);
            mysqli_stmt_store_result($check);
            $departmentExists = mysqli_stmt_num_rows($check) === 1;
            mysqli_stmt_close($check);

            if (!$departmentExists) {
                $error = 'The selected department does not exist.';
            }
        }

        if ($error === '') {
            $name = $form['name'];
            $email = $form['email'] === '' ? null : $form['email'];
            $phone = $form['phone'] === '' ? null : $form['phone'];
            $cgpa = (float)$form['cgpa'];
            $year = (int)$form['graduation_year'];
            $departmentId = (int)$form['department_id'];

            if ($action === 'add') {
                $stmt = mysqli_prepare(
                    $conn,
                    'INSERT INTO Student
                     (name, email, phone, cgpa, graduation_year, department_id)
                     VALUES (?, ?, ?, ?, ?, ?)'
                );

                mysqli_stmt_bind_param(
                    $stmt,
                    'sssdii',
                    $name,
                    $email,
                    $phone,
                    $cgpa,
                    $year,
                    $departmentId
                );
            } else {
                $stmt = mysqli_prepare(
                    $conn,
                    'UPDATE Student
                     SET name = ?, email = ?, phone = ?, cgpa = ?,
                         graduation_year = ?, department_id = ?
                     WHERE student_id = ?'
                );

                mysqli_stmt_bind_param(
                    $stmt,
                    'sssdiii',
                    $name,
                    $email,
                    $phone,
                    $cgpa,
                    $year,
                    $departmentId,
                    $id
                );
            }

            $success = mysqli_stmt_execute($stmt);
            $errno = mysqli_stmt_errno($stmt);
            mysqli_stmt_close($stmt);

            if ($success) {
                redirectStudents($action === 'add' ? 'added' : 'updated');
            }

            if ($errno === 1062) {
                $error = 'That email address is already registered.';
            } else {
                error_log('PlacementHub student save failed. Database error code: ' . $errno);
                $error = 'Could not save the student. Check the data and try again.';
            }
        }

        $editing = ($action === 'update');
    }
}

// Load departments for the form.
$departments = mysqli_query(
    $conn,
    'SELECT department_id, department_name
     FROM Department
     ORDER BY department_name'
);

if (!$departments) {
    error_log('PlacementHub department query failed: ' . mysqli_error($conn));
    exit('Unable to load departments. Please check the database.');
}

$departmentList = [];
while ($department = mysqli_fetch_assoc($departments)) {
    $departmentList[] = $department;
}

// Load an existing student into the edit form.
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && isset($_GET['edit'])) {
    $editId = filter_var($_GET['edit'], FILTER_VALIDATE_INT);

    if ($editId && $editId > 0) {
        $stmt = mysqli_prepare(
            $conn,
            'SELECT student_id, name, email, phone, cgpa,
                    graduation_year, department_id
             FROM Student WHERE student_id = ?'
        );
        mysqli_stmt_bind_param($stmt, 'i', $editId);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $existingStudent = mysqli_fetch_assoc($result);
        mysqli_stmt_close($stmt);

        if ($existingStudent) {
            $form = $existingStudent;
            $editing = true;
        } else {
            $error = 'Student not found.';
        }
    } else {
        $error = 'Invalid student ID.';
    }
}

// Search and filter.
$search = trim($_GET['search'] ?? '');
$departmentFilter = trim($_GET['department'] ?? '');

$sql = "SELECT s.student_id, s.name, s.email, s.phone, s.cgpa,
               s.graduation_year, s.department_id, d.department_name
        FROM Student s
        LEFT JOIN Department d ON s.department_id = d.department_id
        WHERE 1=1";

$types = '';
$params = [];

if ($search !== '') {
    $sql .= " AND (s.name LIKE ? OR s.email LIKE ? OR d.department_name LIKE ?)";
    $like = '%' . $search . '%';
    $types .= 'sss';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

if (
    $departmentFilter !== '' &&
    ctype_digit($departmentFilter) &&
    (int)$departmentFilter > 0
) {
    $sql .= ' AND s.department_id = ?';
    $types .= 'i';
    $params[] = (int)$departmentFilter;
}

$sql .= ' ORDER BY s.student_id DESC';

$stmt = mysqli_prepare($conn, $sql);

if ($types !== '') {
    mysqli_stmt_bind_param($stmt, $types, ...$params);
}

mysqli_stmt_execute($stmt);
$students = mysqli_stmt_get_result($stmt);

// Dashboard numbers.
$totalStudentsResult = mysqli_query($conn, 'SELECT COUNT(*) AS total FROM Student');
$totalStudents = (int)mysqli_fetch_assoc($totalStudentsResult)['total'];

$averageResult = mysqli_query($conn, 'SELECT AVG(cgpa) AS average_cgpa FROM Student');
$averageRow = mysqli_fetch_assoc($averageResult);
$averageCgpa = $averageRow['average_cgpa'] !== null
    ? number_format((float)$averageRow['average_cgpa'], 2)
    : '—';

$graduationResult = mysqli_query(
    $conn,
    'SELECT graduation_year, COUNT(*) AS total
     FROM Student
     GROUP BY graduation_year
     ORDER BY graduation_year DESC'
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Students | PlacementHub</title>
    <link rel="stylesheet" href="style.css">

    <style>
        .main { min-width: 0; }
        .student-stats {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 16px;
            margin-bottom: 22px;
        }
        .student-stat, .student-panel {
            background: #fff;
            border: 1px solid #e8edf5;
            border-radius: 15px;
            padding: 20px;
            box-shadow: 0 5px 20px rgba(15,23,42,.04);
        }
        .student-stat small { color: #64748b; }
        .student-stat strong {
            display: block;
            margin-top: 8px;
            font-size: 27px;
            color: #0f172a;
        }
        .student-panel { margin-bottom: 22px; }
        .student-panel h2 { margin: 0 0 18px; font-size: 19px; }
        .student-form {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 16px;
        }
        .field label {
            display: block;
            margin-bottom: 7px;
            color: #334155;
            font-size: 13px;
            font-weight: 600;
        }
        .field input, .field select, .search-input {
            width: 100%;
            box-sizing: border-box;
            padding: 11px 12px;
            border: 1px solid #cbd5e1;
            border-radius: 9px;
            background: white;
            font-size: 14px;
        }
        .field input:focus, .field select:focus, .search-input:focus {
            outline: 2px solid #bfdbfe;
            border-color: #2563eb;
        }
        .form-actions {
            grid-column: 1 / -1;
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        .btn {
            display: inline-block;
            border: 0;
            border-radius: 9px;
            padding: 11px 15px;
            background: #2563eb;
            color: #fff;
            font-weight: 600;
            font-size: 13px;
            text-decoration: none;
            cursor: pointer;
        }
        .btn:hover { background: #1d4ed8; }
        .btn-secondary { background: #e2e8f0; color: #1e293b; }
        .btn-secondary:hover { background: #cbd5e1; }
        .btn-danger { background: #fee2e2; color: #b91c1c; }
        .btn-danger:hover { background: #fecaca; }
        .notice {
            padding: 13px 15px;
            margin-bottom: 18px;
            border-radius: 10px;
            font-size: 14px;
        }
        .notice-success { background: #dcfce7; color: #166534; }
        .notice-error { background: #fee2e2; color: #991b1b; }
        .search-bar {
            display: grid;
            grid-template-columns: minmax(180px, 1fr) minmax(160px, .7fr) auto auto;
            gap: 10px;
            margin-bottom: 18px;
        }
        .table-wrap { overflow-x: auto; width: 100%; }
        .student-table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
            font-size: 13px;
        }
        .student-table th {
            background: #f8fafc;
            color: #64748b;
            padding: 13px 12px;
            white-space: nowrap;
        }
        .student-table td {
            padding: 13px 12px;
            border-bottom: 1px solid #edf1f7;
            color: #334155;
        }
        .student-table tr:last-child td { border-bottom: 0; }
        .cgpa-pill {
            display: inline-block;
            background: #dcfce7;
            color: #166534;
            padding: 5px 9px;
            border-radius: 20px;
            font-weight: 700;
        }
        .actions { display: flex; gap: 7px; align-items: center; }
        .actions form { margin: 0; }
        .actions .btn { padding: 7px 10px; }
        .muted { color: #64748b; font-size: 12px; }
        .empty { padding: 25px; text-align: center; color: #64748b; }
        @media(max-width: 900px) {
            .student-form { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .search-bar { grid-template-columns: 1fr 1fr; }
        }
        @media(max-width: 600px) {
            .student-stats { grid-template-columns: 1fr; }
            .student-form, .search-bar { grid-template-columns: 1fr; }
            .student-panel { padding: 15px; }
        }
    </style>
</head>
<body>
<div class="dashboard">
    <aside class="sidebar">
        <div class="logo">
            🎓 PlacementHub
            <span>College Placement System</span>
        </div>
        <a href="index.php">🏠 Dashboard</a>
        <a href="students.php" class="active">🎓 Students</a>
        <a href="companies.php">🏢 Companies</a>
        <a href="jobs.php">💼 Jobs</a>
        <a href="applications.php">📄 Applications</a>
        <a href="rounds.php">📝 Selection Rounds</a>
        <a href="placements.php">🏆 Placements</a>
    </aside>

    <main class="main">
        <div class="topbar">
            <div>
                <h1>Student Management</h1>
                <p class="subtitle">Add, update, search and manage student records</p>
            </div>
        </div>

        <?php
        $messages = [
            'added' => 'Student added successfully.',
            'updated' => 'Student details updated successfully.',
            'deleted' => 'Student deleted successfully.',
            'not_found' => 'The student record was not found.',
            'invalid_id' => 'Invalid student ID.',
            'delete_blocked' => 'This student cannot be deleted because related application or placement records exist.'
        ];
        if (isset($messages[$message])): ?>
            <div class="notice <?php echo in_array($message, ['added', 'updated', 'deleted'], true) ? 'notice-success' : 'notice-error'; ?>">
                <?php echo h($messages[$message]); ?>
            </div>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <div class="notice notice-error"><?php echo h($error); ?></div>
        <?php endif; ?>

        <section class="student-stats">
            <div class="student-stat">
                <small>Total registered students</small>
                <strong><?php echo number_format($totalStudents); ?></strong>
            </div>
            <div class="student-stat">
                <small>Average CGPA</small>
                <strong><?php echo h($averageCgpa); ?></strong>
            </div>
            <div class="student-stat">
                <small>Graduation years</small>
                <strong><?php echo mysqli_num_rows($graduationResult); ?></strong>
            </div>
        </section>

        <section class="student-panel">
            <h2><?php echo $editing ? '✏️ Edit Student' : '➕ Add New Student'; ?></h2>

            <form method="post" action="students.php<?php echo $editing ? '?edit=' . (int)$form['student_id'] : ''; ?>">
                <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['placementhub_csrf']); ?>">
                <input type="hidden" name="action" value="<?php echo $editing ? 'update' : 'add'; ?>">

                <?php if ($editing): ?>
                    <input type="hidden" name="student_id" value="<?php echo h($form['student_id']); ?>">
                <?php endif; ?>

                <div class="student-form">
                    <div class="field">
                        <label for="name">Full name *</label>
                        <input id="name" name="name" maxlength="100" required value="<?php echo h($form['name']); ?>" placeholder="Enter student name">
                    </div>

                    <div class="field">
                        <label for="email">Email address</label>
                        <input id="email" type="email" name="email" maxlength="100" value="<?php echo h($form['email']); ?>" placeholder="student@example.com">
                    </div>

                    <div class="field">
                        <label for="phone">Phone number</label>
                        <input id="phone" name="phone" maxlength="15" value="<?php echo h($form['phone']); ?>" placeholder="Enter phone number">
                    </div>

                    <div class="field">
                        <label for="cgpa">CGPA (0–10) *</label>
                        <input id="cgpa" type="number" name="cgpa" min="0" max="10" step="0.01" required value="<?php echo h($form['cgpa']); ?>" placeholder="e.g. 8.50">
                    </div>

                    <div class="field">
                        <label for="graduation_year">Graduation year *</label>
                        <input id="graduation_year" type="number" name="graduation_year" min="2000" max="2100" required value="<?php echo h($form['graduation_year']); ?>">
                    </div>

                    <div class="field">
                        <label for="department_id">Department *</label>
                        <select id="department_id" name="department_id" required>
                            <option value="">Select department</option>
                            <?php foreach ($departmentList as $department): ?>
                                <option value="<?php echo (int)$department['department_id']; ?>"
                                    <?php echo (string)$form['department_id'] === (string)$department['department_id'] ? 'selected' : ''; ?>>
                                    <?php echo h($department['department_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-actions">
                        <button class="btn" type="submit"><?php echo $editing ? 'Save Changes' : 'Add Student'; ?></button>
                        <?php if ($editing): ?>
                            <a class="btn btn-secondary" href="students.php">Cancel Edit</a>
                        <?php endif; ?>
                    </div>
                </div>
            </form>
        </section>

        <section class="student-panel">
            <h2>Student Directory</h2>

            <form method="get" action="students.php" class="search-bar">
                <input class="search-input" type="search" name="search" value="<?php echo h($search); ?>" placeholder="Search name, email or department">

                <select class="search-input" name="department">
                    <option value="">All departments</option>
                    <?php foreach ($departmentList as $department): ?>
                        <option value="<?php echo (int)$department['department_id']; ?>"
                            <?php echo $departmentFilter === (string)$department['department_id'] ? 'selected' : ''; ?>>
                            <?php echo h($department['department_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <button class="btn" type="submit">Search</button>
                <a class="btn btn-secondary" href="students.php">Reset</a>
            </form>

            <div class="table-wrap">
                <table class="student-table">
                    <thead>
                    <tr>
                        <th>ID</th>
                        <th>Student</th>
                        <th>Contact</th>
                        <th>CGPA</th>
                        <th>Graduation</th>
                        <th>Department</th>
                        <th>Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (mysqli_num_rows($students) > 0): ?>
                        <?php while ($row = mysqli_fetch_assoc($students)): ?>
                            <tr>
                                <td><?php echo (int)$row['student_id']; ?></td>
                                <td>
                                    <strong><?php echo h($row['name']); ?></strong><br>
                                    <span class="muted"><?php echo h($row['email'] ?: 'No email'); ?></span>
                                </td>
                                <td><?php echo h($row['phone'] ?: '—'); ?></td>
                                <td><span class="cgpa-pill"><?php echo h($row['cgpa']); ?></span></td>
                                <td><?php echo h($row['graduation_year']); ?></td>
                                <td><?php echo h($row['department_name'] ?: 'Not assigned'); ?></td>
                                <td>
                                    <div class="actions">
                                        <a class="btn" href="students.php?edit=<?php echo (int)$row['student_id']; ?>">Edit</a>

                                        <form method="post" action="students.php" onsubmit="return confirm('Delete this student record? Students with linked applications cannot be deleted.');">
                                            <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['placementhub_csrf']); ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="student_id" value="<?php echo (int)$row['student_id']; ?>">
                                            <button class="btn btn-danger" type="submit">Delete</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" class="empty">No students found. Try another search or add a student above.</td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</div>
</body>
</html>