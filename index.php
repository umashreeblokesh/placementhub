<?php
require_once __DIR__ . '/db.php';

function dashboardCount($conn, $sql) {
    $result = mysqli_query($conn, $sql);

    if (!$result) {
        error_log("PlacementHub dashboard query failed: " . mysqli_error($conn));
        return 0;
    }

    $row = mysqli_fetch_row($result);
    mysqli_free_result($result);

    return (int) ($row[0] ?? 0);
}

function dashboardMoney($conn, $sql) {
    $result = mysqli_query($conn, $sql);

    if (!$result) {
        error_log("PlacementHub dashboard query failed: " . mysqli_error($conn));
        return 0;
    }

    $row = mysqli_fetch_row($result);
    mysqli_free_result($result);

    return (float) ($row[0] ?? 0);
}

function h($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

// Live database statistics
$totalStudents = dashboardCount(
    $conn,
    "SELECT COUNT(*) FROM Student"
);

$totalCompanies = dashboardCount(
    $conn,
    "SELECT COUNT(*) FROM Company"
);

$totalJobs = dashboardCount(
    $conn,
    "SELECT COUNT(*) FROM Job"
);

$totalApplications = dashboardCount(
    $conn,
    "SELECT COUNT(*) FROM Application"
);

$totalPlacements = dashboardCount(
    $conn,
    "SELECT COUNT(*) FROM Placement_Result WHERE final_status = 'Selected'"
);

$totalRejected = dashboardCount(
    $conn,
    "SELECT COUNT(*) FROM Placement_Result WHERE final_status = 'Rejected'"
);

$averagePackage = dashboardMoney(
    $conn,
    "SELECT COALESCE(AVG(j.package), 0)
     FROM Placement_Result pr
     JOIN Application a ON a.application_id = pr.application_id
     JOIN Placement_Drive pd ON pd.drive_id = a.drive_id
     JOIN Job j ON j.job_id = pd.job_id
     WHERE pr.final_status = 'Selected'"
);

$placementRate = $totalApplications > 0
    ? round(($totalPlacements / $totalApplications) * 100, 1)
    : 0;

// Upcoming drives
$upcomingDrives = mysqli_query(
    $conn,
    "SELECT pd.drive_id, pd.drive_date, pd.deadline,
            j.job_role, j.package, c.company_name
     FROM Placement_Drive pd
     JOIN Job j ON j.job_id = pd.job_id
     JOIN Company c ON c.company_id = j.company_id
     WHERE pd.drive_date >= CURDATE()
     ORDER BY pd.drive_date ASC
     LIMIT 5"
);

if (!$upcomingDrives) {
    error_log("PlacementHub upcoming drives query failed: " . mysqli_error($conn));
}

// Recent applications
$recentApplications = mysqli_query(
    $conn,
    "SELECT a.application_id, a.application_date, a.status,
            s.name AS student_name, j.job_role, c.company_name
     FROM Application a
     JOIN Student s ON s.student_id = a.student_id
     JOIN Placement_Drive pd ON pd.drive_id = a.drive_id
     JOIN Job j ON j.job_id = pd.job_id
     JOIN Company c ON c.company_id = j.company_id
     ORDER BY a.application_date DESC, a.application_id DESC
     LIMIT 5"
);

if (!$recentApplications) {
    error_log("PlacementHub recent applications query failed: " . mysqli_error($conn));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PlacementHub | Advanced Dashboard</title>
    <link rel="stylesheet" href="style.css">

    <style>
        .main { min-width: 0; }

        .welcome {
            background: linear-gradient(135deg, #1d4ed8, #3b82f6);
            color: white;
            padding: 30px;
            border-radius: 18px;
            margin-bottom: 24px;
            box-shadow: 0 8px 25px rgba(37, 99, 235, .15);
        }

        .welcome h1 { font-size: 30px; margin: 0 0 10px; }
        .welcome p { opacity: .94; line-height: 1.6; margin: 0; }

        .welcome-tag {
            display: inline-block;
            background: rgba(255,255,255,.16);
            border: 1px solid rgba(255,255,255,.25);
            padding: 6px 11px;
            border-radius: 20px;
            margin-bottom: 14px;
            font-size: 12px;
        }

        .advanced-stats {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 18px;
            margin-bottom: 24px;
        }

        .stat-card {
            background: white;
            border: 1px solid #e8edf5;
            border-radius: 16px;
            padding: 21px;
            box-shadow: 0 5px 20px rgba(15,23,42,.04);
            min-width: 0;
        }

        .stat-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            margin-bottom: 16px;
        }

        .stat-icon {
            width: 43px;
            height: 43px;
            border-radius: 12px;
            display: grid;
            place-items: center;
            background: #eff6ff;
            font-size: 21px;
        }

        .stat-label { color: #64748b; font-size: 13px; }
        .stat-number { font-size: 29px; font-weight: 750; color: #0f172a; }
        .stat-note { color: #64748b; font-size: 12px; margin-top: 7px; }

        .dashboard-grid {
            display: grid;
            grid-template-columns: minmax(0, 1.4fr) minmax(0, 1fr);
            gap: 20px;
            margin-bottom: 24px;
        }

        .panel {
            background: white;
            border: 1px solid #e8edf5;
            border-radius: 16px;
            padding: 22px;
            box-shadow: 0 5px 20px rgba(15,23,42,.04);
            min-width: 0;
        }

        .panel-heading {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            margin-bottom: 18px;
        }

        .panel-heading h2 { font-size: 18px; margin: 0; color: #0f172a; }
        .panel-heading a { color: #2563eb; font-size: 13px; text-decoration: none; }
        .panel-heading a:hover { text-decoration: underline; }

        .metric-row {
            display: flex;
            justify-content: space-between;
            gap: 15px;
            padding: 14px 0;
            border-bottom: 1px solid #edf1f7;
        }

        .metric-row:last-child { border-bottom: 0; }
        .metric-name { color: #64748b; font-size: 13px; }
        .metric-value { color: #0f172a; font-weight: 700; }

        .progress-track {
            height: 9px;
            background: #e2e8f0;
            border-radius: 20px;
            overflow: hidden;
            margin-top: 10px;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #2563eb, #60a5fa);
            border-radius: 20px;
        }

        .table-wrap { width: 100%; overflow-x: auto; }

        .advanced-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
            text-align: left;
        }

        .advanced-table th {
            color: #64748b;
            background: #f8fafc;
            font-weight: 600;
            padding: 12px;
            white-space: nowrap;
        }

        .advanced-table td {
            padding: 13px 12px;
            border-bottom: 1px solid #edf1f7;
            color: #334155;
        }

        .advanced-table tr:last-child td { border-bottom: 0; }

        .status-pill {
            display: inline-block;
            padding: 5px 9px;
            border-radius: 20px;
            background: #eff6ff;
            color: #1d4ed8;
            font-size: 11px;
            font-weight: 700;
        }

        .quick-links {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 16px;
        }

        .quick-card {
            display: block;
            background: white;
            padding: 21px;
            border: 1px solid #e8edf5;
            border-radius: 15px;
            text-decoration: none;
            color: #1e293b;
            box-shadow: 0 5px 20px rgba(15,23,42,.04);
            transition: transform .2s, box-shadow .2s;
        }

        .quick-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 25px rgba(15,23,42,.09);
        }

        .quick-icon { font-size: 27px; margin-bottom: 12px; }
        .quick-card h3 { font-size: 15px; margin: 0 0 7px; }
        .quick-card p { color: #64748b; font-size: 12px; line-height: 1.6; margin: 0; }

        .empty-state { color: #64748b; font-size: 13px; padding: 18px 0; }

        @media (max-width: 1100px) {
            .advanced-stats { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .quick-links { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }

        @media (max-width: 760px) {
            .dashboard-grid { grid-template-columns: 1fr; }
            .welcome { padding: 23px; }
            .welcome h1 { font-size: 25px; }
            .panel { padding: 16px; }
        }

        @media (max-width: 430px) {
            .advanced-stats { gap: 10px; }
            .stat-card { padding: 14px; }
            .stat-number { font-size: 24px; }
            .quick-links { grid-template-columns: 1fr; }
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

        <a href="index.php" class="active">🏠 Dashboard</a>
        <a href="students.php">👨‍🎓 Students</a>
        <a href="companies.php">🏢 Companies</a>
        <a href="jobs.php">💼 Jobs</a>
        <a href="applications.php">📄 Applications</a>
        <a href="rounds.php">📝 Selection Rounds</a>
        <a href="placements.php">🏆 Placements</a>
    </aside>

    <main class="main">
        <div class="topbar">
            <div>
                <h1>Dashboard</h1>
                <p class="subtitle">Placement performance and recruitment overview</p>
            </div>
            <div style="color:#64748b;font-size:13px;">
                <?php echo h(date('d M Y')); ?>
            </div>
        </div>

        <section class="welcome">
            <div class="welcome-tag">PLACEMENT MANAGEMENT • OVERVIEW</div>
            <h1>Welcome to PlacementHub 👋</h1>
            <p>Monitor student applications, recruiting companies, job opportunities and placement outcomes in one place.</p>
        </section>

        <section class="advanced-stats">
            <div class="stat-card">
                <div class="stat-top">
                    <span class="stat-label">Registered Students</span>
                    <span class="stat-icon">🎓</span>
                </div>
                <div class="stat-number"><?php echo number_format($totalStudents); ?></div>
                <div class="stat-note">Student records in the database</div>
            </div>

            <div class="stat-card">
                <div class="stat-top">
                    <span class="stat-label">Recruiting Companies</span>
                    <span class="stat-icon">🏢</span>
                </div>
                <div class="stat-number"><?php echo number_format($totalCompanies); ?></div>
                <div class="stat-note">Companies registered in PlacementHub</div>
            </div>

            <div class="stat-card">
                <div class="stat-top">
                    <span class="stat-label">Job Opportunities</span>
                    <span class="stat-icon">💼</span>
                </div>
                <div class="stat-number"><?php echo number_format($totalJobs); ?></div>
                <div class="stat-note">Roles available in the job catalogue</div>
            </div>

            <div class="stat-card">
                <div class="stat-top">
                    <span class="stat-label">Selected Candidates</span>
                    <span class="stat-icon">🏆</span>
                </div>
                <div class="stat-number"><?php echo number_format($totalPlacements); ?></div>
                <div class="stat-note">Final results marked Selected</div>
            </div>
        </section>

        <section class="dashboard-grid">
            <div class="panel">
                <div class="panel-heading">
                    <h2>Recruitment Overview</h2>
                    <a href="applications.php">View applications →</a>
                </div>

                <div class="metric-row">
                    <span class="metric-name">Total applications</span>
                    <span class="metric-value"><?php echo number_format($totalApplications); ?></span>
                </div>

                <div class="metric-row">
                    <span class="metric-name">Selected candidates</span>
                    <span class="metric-value"><?php echo number_format($totalPlacements); ?></span>
                </div>

                <div class="metric-row">
                    <span class="metric-name">Rejected final results</span>
                    <span class="metric-value"><?php echo number_format($totalRejected); ?></span>
                </div>

                <div style="margin-top:20px;">
                    <div style="display:flex;justify-content:space-between;gap:10px;">
                        <span class="metric-name">Applications resulting in selection</span>
                        <strong><?php echo h(number_format($placementRate, 1)); ?>%</strong>
                    </div>
                    <div class="progress-track">
                        <div class="progress-fill" style="width:<?php echo min(100, max(0, $placementRate)); ?>%;"></div>
                    </div>
                    <p class="stat-note">Calculated as selected final results divided by all applications. This is an overview metric, not an official placement rate for unique students.</p>
                </div>

                <div class="metric-row">
                    <span class="metric-name">Average package for selected results</span>
                    <span class="metric-value">₹<?php echo h(number_format($averagePackage, 2)); ?> LPA</span>
                </div>
            </div>

            <div class="panel">
                <div class="panel-heading">
                    <h2>Upcoming Placement Drives</h2>
                    <a href="jobs.php">Explore jobs →</a>
                </div>

                <?php if ($upcomingDrives && mysqli_num_rows($upcomingDrives) > 0): ?>
                    <div class="table-wrap">
                        <table class="advanced-table">
                            <thead>
                                <tr>
                                    <th>Company / Role</th>
                                    <th>Drive Date</th>
                                    <th>Package</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php while ($drive = mysqli_fetch_assoc($upcomingDrives)): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo h($drive['company_name']); ?></strong><br>
                                        <span style="color:#64748b;"><?php echo h($drive['job_role']); ?></span>
                                    </td>
                                    <td><?php echo h(date('d M Y', strtotime($drive['drive_date']))); ?></td>
                                    <td>₹<?php echo h(number_format((float)$drive['package'], 2)); ?> LPA</td>
                                </tr>
                            <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="empty-state">No upcoming placement drives were found.</p>
                <?php endif; ?>
            </div>
        </section>

        <section class="panel" style="margin-bottom:24px;">
            <div class="panel-heading">
                <h2>Recent Applications</h2>
                <a href="applications.php">View all →</a>
            </div>

            <?php if ($recentApplications && mysqli_num_rows($recentApplications) > 0): ?>
                <div class="table-wrap">
                    <table class="advanced-table">
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>Company</th>
                                <th>Job Role</th>
                                <th>Applied On</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php while ($application = mysqli_fetch_assoc($recentApplications)): ?>
                            <tr>
                                <td><?php echo h($application['student_name']); ?></td>
                                <td><?php echo h($application['company_name']); ?></td>
                                <td><?php echo h($application['job_role']); ?></td>
                                <td><?php echo h(date('d M Y', strtotime($application['application_date']))); ?></td>
                                <td><span class="status-pill"><?php echo h($application['status']); ?></span></td>
                            </tr>
                        <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p class="empty-state">No applications are available yet.</p>
            <?php endif; ?>
        </section>

        <section class="panel">
            <div class="panel-heading">
                <h2>Quick Access</h2>
                <span class="stat-note">Manage your placement data</span>
            </div>

            <div class="quick-links">
                <a href="students.php" class="quick-card">
                    <div class="quick-icon">🎓</div>
                    <h3>Students</h3>
                    <p>View student profiles and academic details.</p>
                </a>

                <a href="companies.php" class="quick-card">
                    <div class="quick-icon">🏢</div>
                    <h3>Companies</h3>
                    <p>View recruiting organizations.</p>
                </a>

                <a href="jobs.php" class="quick-card">
                    <div class="quick-icon">💼</div>
                    <h3>Jobs</h3>
                    <p>Explore roles, packages and eligibility.</p>
                </a>

                <a href="applications.php" class="quick-card">
                    <div class="quick-icon">📄</div>
                    <h3>Applications</h3>
                    <p>Review application records and statuses.</p>
                </a>
            </div>
        </section>
    </main>
</div>
</body>
</html>