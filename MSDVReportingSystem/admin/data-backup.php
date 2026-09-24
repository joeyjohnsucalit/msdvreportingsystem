<?php
session_start();
require_once '../db/connection.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../login.php');
    exit;
}

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

/* =========================================================
   NOTIFICATIONS
========================================================= */

$unreadStmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM notifications
    WHERE user_id = ? AND is_read = 0
");
$unreadStmt->execute([$_SESSION['user_id']]);
$unreadCount = (int)$unreadStmt->fetchColumn();

$notifStmt = $pdo->prepare("
    SELECT *
    FROM notifications
    WHERE user_id = ?
    ORDER BY created_at DESC
    LIMIT 20
");
$notifStmt->execute([$_SESSION['user_id']]);
$notifList = $notifStmt->fetchAll();


/* =========================================================
   EXCEL EXPORT FUNCTION
========================================================= */

function excelExport($filename, $sheets)
{
    $filename = preg_replace('/[^A-Za-z0-9_\-]/', '_', $filename);

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '.xlsx"');
    header('Cache-Control: max-age=0');

    $sheetXmls = [];
    $sheetRels = [];
    $contentTypes = [];

    foreach ($sheets as $i => $sheet) {

        $sheetId = $i + 1;

        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';

        $xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
        $xml .= '<sheetData>';

        /* Headers */
        $xml .= '<row>';

        foreach ($sheet['headers'] as $h) {
            $xml .= '<c t="inlineStr"><is><t>'
                . htmlspecialchars((string)$h, ENT_XML1)
                . '</t></is></c>';
        }

        $xml .= '</row>';

        /* Rows */
        foreach ($sheet['rows'] as $row) {

            $xml .= '<row>';

            foreach ($row as $cell) {

                $val = htmlspecialchars(
                    (string)($cell ?? ''),
                    ENT_XML1
                );

                if (is_numeric($cell) && $cell !== '') {

                    $xml .= '<c><v>'
                        . $val
                        . '</v></c>';

                } else {

                    $xml .= '<c t="inlineStr"><is><t>'
                        . $val
                        . '</t></is></c>';
                }
            }

            $xml .= '</row>';
        }

        $xml .= '</sheetData>';
        $xml .= '</worksheet>';

        $sheetXmls[$sheetId] = $xml;

        $sheetRels[] =
            '<sheet name="' .
            htmlspecialchars($sheet['title'], ENT_XML1) .
            '" sheetId="' .
            $sheetId .
            '" r:id="rId' .
            $sheetId .
            '"/>';

        $contentTypes[] =
            '<Override PartName="/xl/worksheets/sheet' .
            $sheetId .
            '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
    }


    /* Workbook */
    $wbXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';

    $wbXml .=
        '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" ' .
        'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';

    $wbXml .= '<sheets>';
    $wbXml .= implode('', $sheetRels);
    $wbXml .= '</sheets>';
    $wbXml .= '</workbook>';


    /* Workbook relationships */
    $wbRels =
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';

    $wbRels .=
        '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';

    foreach ($sheetXmls as $id => $_) {

        $wbRels .=
            '<Relationship Id="rId' .
            $id .
            '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" ' .
            'Target="worksheets/sheet' .
            $id .
            '.xml"/>';
    }

    $wbRels .= '</Relationships>';


    /* Content Types */
    $ct =
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';

    $ct .=
        '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">';

    $ct .=
        '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>';

    $ct .=
        '<Default Extension="xml" ContentType="application/xml"/>';

    $ct .=
        '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>';

    $ct .= implode('', $contentTypes);

    $ct .= '</Types>';


    /* Root relationships */
    $rels =
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';

    $rels .=
        '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';

    $rels .=
        '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>';

    $rels .= '</Relationships>';


    /* Create temporary XLSX */
    $tmp = tempnam(sys_get_temp_dir(), 'xlsx_');

    $zip = new ZipArchive();

    $zip->open($tmp, ZipArchive::OVERWRITE);

    $zip->addFromString('[Content_Types].xml', $ct);
    $zip->addFromString('_rels/.rels', $rels);
    $zip->addFromString('xl/workbook.xml', $wbXml);
    $zip->addFromString('xl/_rels/workbook.xml.rels', $wbRels);

    foreach ($sheetXmls as $id => $xml) {

        $zip->addFromString(
            'xl/worksheets/sheet' . $id . '.xml',
            $xml
        );
    }

    $zip->close();

    readfile($tmp);

    unlink($tmp);

    exit;
}


/* =========================================================
   EXPORT REQUEST
========================================================= */

if (isset($_GET['export'])) {

    $type = $_GET['export'];
    $date = date('Ymd_His');


    /* -----------------------------------------------------
       STUDENTS
    ----------------------------------------------------- */

    if ($type === 'students') {

        $rows = $pdo->query("
            SELECT
                s.student_id,
                s.full_name,
                c.name,
                s.year_level,
                d.name
            FROM students s
            JOIN courses c
                ON s.course_id = c.id
            JOIN departments d
                ON s.department_id = d.id
            ORDER BY s.student_id
        ")->fetchAll(PDO::FETCH_NUM);

        excelExport(
            'students_export_' . $date,
            [[
                'title' => 'Students',
                'headers' => [
                    'Student ID',
                    'Full Name',
                    'Course',
                    'Year Level',
                    'Department'
                ],
                'rows' => $rows
            ]]
        );
    }


    /* -----------------------------------------------------
       VIOLATIONS
    ----------------------------------------------------- */

    elseif ($type === 'violations') {

        $rows = $pdo->query("
            SELECT
                v.id,
                v.student_id,
                s.full_name,
                v.category,
                v.violation,
                v.description,
                v.status,
                v.date_submitted
            FROM violations v
            JOIN students s
                ON v.student_id = s.student_id
            ORDER BY v.date_submitted DESC
        ")->fetchAll(PDO::FETCH_NUM);

        excelExport(
            'violations_export_' . $date,
            [[
                'title' => 'Violations',
                'headers' => [
                    'ID',
                    'Student ID',
                    'Full Name',
                    'Category',
                    'Violation',
                    'Description',
                    'Status',
                    'Date Submitted'
                ],
                'rows' => $rows
            ]]
        );
    }


    /* -----------------------------------------------------
       DISCIPLINARY
    ----------------------------------------------------- */

    elseif ($type === 'disciplinary') {

        $rows = $pdo->query("
            SELECT
                da.id,
                da.student_id,
                s.full_name,
                v.violation,
                da.sanction,
                da.status,
                da.start_date,
                da.end_date
            FROM disciplinary_actions da
            JOIN students s
                ON da.student_id = s.student_id
            JOIN violations v
                ON da.violation_id = v.id
            ORDER BY da.id DESC
        ")->fetchAll(PDO::FETCH_NUM);

        excelExport(
            'disciplinary_export_' . $date,
            [[
                'title' => 'Disciplinary Actions',
                'headers' => [
                    'ID',
                    'Student ID',
                    'Full Name',
                    'Violation',
                    'Sanction',
                    'Status',
                    'Start Date',
                    'End Date'
                ],
                'rows' => $rows
            ]]
        );
    }


    /* -----------------------------------------------------
       RISK
    ----------------------------------------------------- */

    elseif ($type === 'risk') {

        $raw = $pdo->query("
            SELECT
                s.student_id,
                s.full_name,

                SUM(
                    CASE
                        WHEN v.category = 'minor'
                        THEN 1
                        ELSE 0
                    END
                ) AS minor_c,

                SUM(
                    CASE
                        WHEN v.category = 'major'
                        THEN 1
                        ELSE 0
                    END
                ) AS major_c,

                COUNT(v.id) AS total

            FROM students s

            LEFT JOIN violations v
                ON s.student_id = v.student_id

            GROUP BY s.student_id

            ORDER BY total DESC
        ")->fetchAll();


        $rows = [];

        foreach ($raw as $r) {

            $t = (int)$r['total'];

            $risk =
                $t >= 5
                    ? 'Critical'
                    : (
                        $t >= 4
                            ? 'High'
                            : (
                                $t >= 2
                                    ? 'Moderate'
                                    : 'Low'
                            )
                    );

            $rows[] = [
                $r['student_id'],
                $r['full_name'],
                $r['minor_c'],
                $r['major_c'],
                $t,
                $risk
            ];
        }


        excelExport(
            'risk_export_' . $date,
            [[
                'title' => 'Risk Levels',
                'headers' => [
                    'Student ID',
                    'Full Name',
                    'Minor Count',
                    'Major Count',
                    'Total',
                    'Risk Level'
                ],
                'rows' => $rows
            ]]
        );
    }


    /* -----------------------------------------------------
       ALL DATA
    ----------------------------------------------------- */

    elseif ($type === 'all') {

        $sheets = [];


        /* Students */
        $sheets[] = [
            'title' => 'Students',
            'headers' => [
                'Student ID',
                'Full Name',
                'Course',
                'Year Level',
                'Department'
            ],
            'rows' => $pdo->query("
                SELECT
                    s.student_id,
                    s.full_name,
                    c.name,
                    s.year_level,
                    d.name
                FROM students s
                JOIN courses c
                    ON s.course_id = c.id
                JOIN departments d
                    ON s.department_id = d.id
            ")->fetchAll(PDO::FETCH_NUM)
        ];


        /* Violations */
        $sheets[] = [
            'title' => 'Violations',
            'headers' => [
                'ID',
                'Student ID',
                'Full Name',
                'Category',
                'Violation',
                'Description',
                'Status',
                'Date'
            ],
            'rows' => $pdo->query("
                SELECT
                    v.id,
                    v.student_id,
                    s.full_name,
                    v.category,
                    v.violation,
                    v.description,
                    v.status,
                    v.date_submitted
                FROM violations v
                JOIN students s
                    ON v.student_id = s.student_id
            ")->fetchAll(PDO::FETCH_NUM)
        ];


        /* Disciplinary */
        $sheets[] = [
            'title' => 'Disciplinary Actions',
            'headers' => [
                'ID',
                'Student ID',
                'Violation',
                'Sanction',
                'Status',
                'Start Date',
                'End Date'
            ],
            'rows' => $pdo->query("
                SELECT
                    da.id,
                    da.student_id,
                    v.violation,
                    da.sanction,
                    da.status,
                    da.start_date,
                    da.end_date
                FROM disciplinary_actions da
                JOIN violations v
                    ON da.violation_id = v.id
            ")->fetchAll(PDO::FETCH_NUM)
        ];


        /* Risk */
        $raw = $pdo->query("
            SELECT
                s.student_id,
                s.full_name,

                SUM(
                    CASE
                        WHEN v.category = 'minor'
                        THEN 1
                        ELSE 0
                    END
                ) AS mc,

                SUM(
                    CASE
                        WHEN v.category = 'major'
                        THEN 1
                        ELSE 0
                    END
                ) AS mj,

                COUNT(v.id) AS total

            FROM students s

            LEFT JOIN violations v
                ON s.student_id = v.student_id

            GROUP BY s.student_id
        ")->fetchAll();


        $riskRows = [];

        foreach ($raw as $r) {

            $t = (int)$r['total'];

            $risk =
                $t >= 5
                    ? 'Critical'
                    : (
                        $t >= 4
                            ? 'High'
                            : (
                                $t >= 2
                                    ? 'Moderate'
                                    : 'Low'
                            )
                    );

            $riskRows[] = [
                $r['student_id'],
                $r['full_name'],
                $r['mc'],
                $r['mj'],
                $t,
                $risk
            ];
        }


        $sheets[] = [
            'title' => 'Risk Levels',
            'headers' => [
                'Student ID',
                'Full Name',
                'Minor',
                'Major',
                'Total',
                'Risk Level'
            ],
            'rows' => $riskRows
        ];


        excelExport(
            'full_export_' . $date,
            $sheets
        );
    }
}


/* =========================================================
   DASHBOARD COUNTS
========================================================= */

$studentCount = $pdo->query("
    SELECT COUNT(*)
    FROM students
")->fetchColumn();

$violationCount = $pdo->query("
    SELECT COUNT(*)
    FROM violations
")->fetchColumn();

$disciplinaryCount = $pdo->query("
    SELECT COUNT(*)
    FROM disciplinary_actions
")->fetchColumn();


/* =========================================================
   PREVIEW DATA
========================================================= */

$previewStudents = $pdo->query("
    SELECT
        s.student_id,
        s.full_name,
        c.name AS cname,
        s.year_level,
        d.name AS dname
    FROM students s
    JOIN courses c
        ON s.course_id = c.id
    JOIN departments d
        ON s.department_id = d.id
    ORDER BY s.student_id
    LIMIT 8
")->fetchAll();


$previewViolations = $pdo->query("
    SELECT
        v.student_id,
        s.full_name,
        v.category,
        v.violation,
        v.status,
        v.date_submitted
    FROM violations v
    JOIN students s
        ON v.student_id = s.student_id
    ORDER BY v.date_submitted DESC
    LIMIT 8
")->fetchAll();


$previewDisciplinary = $pdo->query("
    SELECT
        da.student_id,
        s.full_name,
        v.violation,
        da.sanction,
        da.status
    FROM disciplinary_actions da
    JOIN students s
        ON da.student_id = s.student_id
    JOIN violations v
        ON da.violation_id = v.id
    ORDER BY da.id DESC
    LIMIT 8
")->fetchAll();


$previewRisk = $pdo->query("
    SELECT
        s.student_id,
        s.full_name,

        SUM(
            CASE
                WHEN v.category = 'minor'
                THEN 1
                ELSE 0
            END
        ) AS minor_c,

        SUM(
            CASE
                WHEN v.category = 'major'
                THEN 1
                ELSE 0
            END
        ) AS major_c,

        COUNT(v.id) AS total

    FROM students s

    LEFT JOIN violations v
        ON s.student_id = v.student_id

    GROUP BY s.student_id

    ORDER BY total DESC

    LIMIT 8
")->fetchAll();

?>
<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>MSDV | Data Backup</title>


    <!-- Font Awesome -->
    <link
        rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"
    >


    <!-- Bootstrap -->
    <link
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css"
    >


    <!-- Google Font -->
    <link rel="preconnect" href="https://fonts.googleapis.com">

    <link
        rel="preconnect"
        href="https://fonts.gstatic.com"
        crossorigin
    >

    <link
        href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600&display=swap"
        rel="stylesheet"
    >


    <!-- Page CSS -->
    <link
        rel="stylesheet"
        href="../assets/css/admin_data_backup.css"
    >

</head>


<body>


<!-- =====================================================
     SIDEBAR
===================================================== -->

<div class="sidebar">

    <div class="adm_logo">

        <img
            src="../assets/images/mccLogo.png"
            alt="MCC Logo"
        >

        <div class="logotext">

            <h2>Admin Panel</h2>

            <p>MCC Student Violation System</p>

        </div>

    </div>


    <div class="sidebar_content">

        <ul>

            <h3>Overview</h3>

            <li>
                <a href="dashboard.php">
                    <i class="fas fa-chart-line"></i>
                    <span>Dashboard</span>
                </a>
            </li>


            <h3>Students</h3>

            <li>
                <a href="student-records.php">
                    <i class="fas fa-user-graduate"></i>
                    <span>Student Records</span>
                </a>
            </li>

            <li>
                <a href="violation-records.php">
                    <i class="fas fa-history"></i>
                    <span>Violation Records</span>
                </a>
            </li>

            <li>
                <a href="student-appeals.php">
                    <i class="fa-solid fa-pen"></i>
                    <span>Student Appeals</span>
                </a>
            </li>


            <h3>Discipline</h3>

            <li>
                <a href="disciplinary-action.php">
                    <i class="fas fa-gavel"></i>
                    <span>Disciplinary Actions</span>
                </a>
            </li>

            <li>
                <a href="risk-level.php">
                    <i class="fas fa-exclamation-triangle"></i>
                    <span>Risk Level Indicator</span>
                </a>
            </li>


            <h3>Backup &amp; Reports</h3>

            <li>
                <a
                    href="data-backup.php"
                    class="active"
                >
                    <i class="fas fa-cloud-download-alt"></i>
                    <span>Data Backup</span>
                </a>
            </li>


            <h3>System</h3>

            <li>
                <a href="user-management.php">
                    <i class="fas fa-user-cog"></i>
                    <span>User Management</span>
                </a>
            </li>

            <li>
                <a href="../logout.php">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>Log Out</span>
                </a>
            </li>

        </ul>

    </div>

</div>



<!-- =====================================================
     MAIN CONTENT
===================================================== -->

<div class="main-content">


    <!-- NAVBAR -->

    <nav class="navbar">

        <h1>Data Backup &amp; Export</h1>


        <div class="navbar-right">


            <!-- Notifications -->

            <div class="dropdown bell-wrap">

                <button
                    class="bell-btn"
                    id="bellBtn"
                    data-bs-toggle="dropdown"
                    data-bs-offset="0,8"
                    aria-expanded="false"
                >

                    <i class="fas fa-bell"></i>

                    <?php if ($unreadCount > 0): ?>

                        <span
                            class="notif-badge"
                            id="notifBadge"
                        >
                            <?= $unreadCount ?>
                        </span>

                    <?php endif; ?>

                </button>


                <div class="dropdown-menu dropdown-menu-end notif-dropdown p-0">


                    <div class="notif-head">

                        <div class="notif-head-left">

                            <span>Notifications</span>

                            <span
                                class="unread-pill <?= $unreadCount > 0 ? 'visible' : '' ?>"
                                id="notifPill"
                            >
                                <?= $unreadCount ?> new
                            </span>

                        </div>


                        <button
                            class="mark-all-btn"
                            onclick="markAllRead()"
                        >
                            Mark all read
                        </button>

                    </div>


                    <div
                        class="notif-scroll"
                        id="notifList"
                    >

                        <?php if (empty($notifList)): ?>

                            <div class="notif-empty">

                                <i class="fas fa-bell-slash"></i>

                                <p>You're all caught up</p>

                            </div>

                        <?php else: ?>

                            <?php foreach ($notifList as $n): ?>

                                <a
                                    class="notif-item <?= !$n['is_read'] ? 'unread' : '' ?>"
                                    href="<?= htmlspecialchars($n['link'] ?? '#') ?>"
                                    onclick="markRead(<?= $n['id'] ?>,this);return true;"
                                >

                                    <div class="notif-avatar">

                                        <i class="fas fa-exclamation"></i>

                                    </div>


                                    <div class="notif-body-text">

                                        <div class="notif-msg">

                                            <?= htmlspecialchars($n['message']) ?>

                                        </div>


                                        <div class="notif-ts">

                                            <i
                                                class="fas fa-clock"
                                                style="margin-right:3px;font-size:9px;"
                                            ></i>

                                            <?= date(
                                                'M d, Y · h:i A',
                                                strtotime($n['created_at'])
                                            ) ?>

                                        </div>

                                    </div>


                                    <?php if (!$n['is_read']): ?>

                                        <div class="notif-dot-ind"></div>

                                    <?php endif; ?>

                                </a>

                            <?php endforeach; ?>

                        <?php endif; ?>

                    </div>

                </div>

            </div>


            <!-- USER -->

            <div class="user-chip">

                <div class="user-avatar">

                    <?= strtoupper(
                        substr($_SESSION['full_name'], 0, 1)
                    ) ?>

                </div>

                <span class="user-name">

                    <?= htmlspecialchars($_SESSION['full_name']) ?>

                </span>

            </div>

        </div>

    </nav>



    <!-- =================================================
         PAGE CONTENT
    ================================================= -->

    <div class="content">


        <!-- PAGE TOP -->

        <div class="page-top">

            <a
                href="?export=all"
                class="btn-export-all"
            >

                <i
                    class="fas fa-file-excel"
                    style="font-size:13px;"
                ></i>

                Export All Data

            </a>

        </div>



        <!-- =================================================
             STUDENTS
        ================================================= -->

        <div class="section-card">

            <div class="section-card-header">

                <div class="section-card-header-left">

                    <i class="fas fa-user-graduate"></i>

                    <span class="section-title">
                        Student Records
                    </span>

                    <span class="record-count-pill">
                        <?= $studentCount ?>
                    </span>

                </div>


                <a
                    href="?export=students"
                    class="btn-export blue"
                >

                    <i
                        class="fas fa-file-excel"
                        style="font-size:11px;"
                    ></i>

                    Export Excel

                </a>

            </div>


            <div style="overflow-x:auto;">

                <table class="data-table">

                    <thead>

                        <tr>

                            <th>Student ID</th>
                            <th>Full Name</th>
                            <th>Course</th>
                            <th>Year</th>
                            <th>Department</th>

                        </tr>

                    </thead>


                    <tbody>

                        <?php if (empty($previewStudents)): ?>

                            <tr class="empty-row">

                                <td colspan="5">
                                    No students yet.
                                </td>

                            </tr>

                        <?php else: ?>

                            <?php foreach ($previewStudents as $s): ?>

                                <tr>

                                    <td class="sid">
                                        <?= htmlspecialchars($s['student_id']) ?>
                                    </td>

                                    <td class="name">
                                        <?= htmlspecialchars($s['full_name']) ?>
                                    </td>

                                    <td class="muted">
                                        <?= htmlspecialchars($s['cname']) ?>
                                    </td>

                                    <td class="muted">
                                        Year <?= $s['year_level'] ?>
                                    </td>

                                    <td class="muted">
                                        <?= htmlspecialchars($s['dname']) ?>
                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        <?php endif; ?>

                    </tbody>

                </table>

            </div>


            <?php if ($studentCount > 8): ?>

                <div class="section-card-footer">

                    <i
                        class="fas fa-info-circle"
                        style="margin-right:5px;"
                    ></i>

                    Showing 8 of
                    <?= $studentCount ?>
                    records. Export to see all.

                </div>

            <?php endif; ?>

        </div>



        <!-- =================================================
             VIOLATIONS
        ================================================= -->

        <div class="section-card">

            <div class="section-card-header">

                <div class="section-card-header-left">

                    <i class="fas fa-exclamation-triangle"></i>

                    <span class="section-title">
                        Violation Records
                    </span>

                    <span class="record-count-pill">
                        <?= $violationCount ?>
                    </span>

                </div>


                <a
                    href="?export=violations"
                    class="btn-export red"
                >

                    <i
                        class="fas fa-file-excel"
                        style="font-size:11px;"
                    ></i>

                    Export Excel

                </a>

            </div>


            <div style="overflow-x:auto;">

                <table class="data-table">

                    <thead>

                        <tr>

                            <th>Student ID</th>
                            <th>Name</th>
                            <th>Type</th>
                            <th>Violation</th>
                            <th>Status</th>
                            <th>Date</th>

                        </tr>

                    </thead>


                    <tbody>

                        <?php if (empty($previewViolations)): ?>

                            <tr class="empty-row">

                                <td colspan="6">
                                    No violations yet.
                                </td>

                            </tr>

                        <?php else: ?>

                            <?php foreach ($previewViolations as $v): ?>

                                <tr>

                                    <td class="sid">
                                        <?= htmlspecialchars($v['student_id']) ?>
                                    </td>

                                    <td class="name">
                                        <?= htmlspecialchars($v['full_name']) ?>
                                    </td>

                                    <td>

                                        <span
                                            class="mini-badge <?= htmlspecialchars($v['category']) ?>"
                                        >
                                            <?= ucfirst($v['category']) ?>
                                        </span>

                                    </td>

                                    <td class="muted">
                                        <?= htmlspecialchars($v['violation']) ?>
                                    </td>

                                    <td>

                                        <span
                                            class="mini-badge <?= htmlspecialchars($v['status']) ?>"
                                        >
                                            <?= ucfirst($v['status']) ?>
                                        </span>

                                    </td>

                                    <td class="muted">

                                        <?= date(
                                            'M d, Y',
                                            strtotime($v['date_submitted'])
                                        ) ?>

                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        <?php endif; ?>

                    </tbody>

                </table>

            </div>


            <?php if ($violationCount > 8): ?>

                <div class="section-card-footer">

                    <i
                        class="fas fa-info-circle"
                        style="margin-right:5px;"
                    ></i>

                    Showing 8 of
                    <?= $violationCount ?>
                    records. Export to see all.

                </div>

            <?php endif; ?>

        </div>



        <!-- =================================================
             DISCIPLINARY ACTIONS
        ================================================= -->

        <div class="section-card">

            <div class="section-card-header">

                <div class="section-card-header-left">

                    <i class="fas fa-gavel"></i>

                    <span class="section-title">
                        Disciplinary Actions
                    </span>

                    <span class="record-count-pill">
                        <?= $disciplinaryCount ?>
                    </span>

                </div>


                <a
                    href="?export=disciplinary"
                    class="btn-export amber"
                >

                    <i
                        class="fas fa-file-excel"
                        style="font-size:11px;"
                    ></i>

                    Export Excel

                </a>

            </div>


            <div style="overflow-x:auto;">

                <table class="data-table">

                    <thead>

                        <tr>

                            <th>Student ID</th>
                            <th>Name</th>
                            <th>Violation</th>
                            <th>Sanction</th>
                            <th>Status</th>

                        </tr>

                    </thead>


                    <tbody>

                        <?php if (empty($previewDisciplinary)): ?>

                            <tr class="empty-row">

                                <td colspan="5">
                                    No disciplinary records yet.
                                </td>

                            </tr>

                        <?php else: ?>

                            <?php foreach ($previewDisciplinary as $da): ?>

                                <tr>

                                    <td class="sid">
                                        <?= htmlspecialchars($da['student_id']) ?>
                                    </td>

                                    <td class="name">
                                        <?= htmlspecialchars($da['full_name']) ?>
                                    </td>

                                    <td class="muted">
                                        <?= htmlspecialchars($da['violation']) ?>
                                    </td>

                                    <td
                                        class="muted"
                                        style="max-width:240px;"
                                    >

                                        <small>

                                            <?= htmlspecialchars(
                                                substr(
                                                    $da['sanction'],
                                                    0,
                                                    60
                                                )
                                            ) ?>…

                                        </small>

                                    </td>

                                    <td>

                                        <span
                                            class="mini-badge <?= htmlspecialchars($da['status']) ?>"
                                        >
                                            <?= ucfirst($da['status']) ?>
                                        </span>

                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        <?php endif; ?>

                    </tbody>

                </table>

            </div>


            <?php if ($disciplinaryCount > 8): ?>

                <div class="section-card-footer">

                    <i
                        class="fas fa-info-circle"
                        style="margin-right:5px;"
                    ></i>

                    Showing 8 of
                    <?= $disciplinaryCount ?>
                    records. Export to see all.

                </div>

            <?php endif; ?>

        </div>



        <!-- =================================================
             RISK LEVEL
        ================================================= -->

        <div class="section-card">

            <div class="section-card-header">

                <div class="section-card-header-left">

                    <i class="fas fa-shield-alt"></i>

                    <span class="section-title">
                        Risk Level Data
                    </span>

                </div>


                <a
                    href="?export=risk"
                    class="btn-export teal"
                >

                    <i
                        class="fas fa-file-excel"
                        style="font-size:11px;"
                    ></i>

                    Export Excel

                </a>

            </div>


            <div style="overflow-x:auto;">

                <table class="data-table">

                    <thead>

                        <tr>

                            <th>Student ID</th>
                            <th>Full Name</th>
                            <th>Minor</th>
                            <th>Major</th>
                            <th>Total</th>
                            <th>Risk Level</th>

                        </tr>

                    </thead>


                    <tbody>

                        <?php if (empty($previewRisk)): ?>

                            <tr class="empty-row">

                                <td colspan="6">
                                    No data yet.
                                </td>

                            </tr>

                        <?php else: ?>

                            <?php foreach ($previewRisk as $r): ?>

                                <?php

                                $t = (int)$r['total'];

                                if ($t >= 5) {

                                    $rl = 'Critical';
                                    $rc = '#dc2626';
                                    $rb = '#fff0f0';

                                } elseif ($t >= 4) {

                                    $rl = 'High';
                                    $rc = '#d97706';
                                    $rb = '#fffbeb';

                                } elseif ($t >= 2) {

                                    $rl = 'Moderate';
                                    $rc = '#2563eb';
                                    $rb = '#eff4ff';

                                } else {

                                    $rl = 'Low';
                                    $rc = '#16a34a';
                                    $rb = '#f0fdf4';
                                }

                                ?>

                                <tr>

                                    <td class="sid">
                                        <?= htmlspecialchars($r['student_id']) ?>
                                    </td>

                                    <td class="name">
                                        <?= htmlspecialchars($r['full_name']) ?>
                                    </td>

                                    <td class="muted">
                                        <?= $r['minor_c'] ?>
                                    </td>

                                    <td class="muted">
                                        <?= $r['major_c'] ?>
                                    </td>

                                    <td class="muted">
                                        <?= $t ?>
                                    </td>

                                    <td>

                                        <span
                                            class="risk-badge"
                                            style="
                                                background:<?= $rb ?>;
                                                color:<?= $rc ?>;
                                            "
                                        >

                                            <span
                                                class="dot"
                                                style="
                                                    background:<?= $rc ?>;
                                                "
                                            ></span>

                                            <?= $rl ?>

                                        </span>

                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </div>


    </div>

</div>



<!-- =====================================================
     JAVASCRIPT
===================================================== -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>


<script>

function escHtml(s) {

    return String(s)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}


function fmtDate(s) {

    const d = new Date(s);

    return d.toLocaleDateString(
        'en-US',
        {
            month: 'short',
            day: 'numeric',
            year: 'numeric'
        }
    )
    +
    ' · '
    +
    d.toLocaleTimeString(
        'en-US',
        {
            hour: '2-digit',
            minute: '2-digit'
        }
    );
}


/* =====================================================
   RENDER NOTIFICATIONS
===================================================== */

function renderNotifs(data) {

    const badge =
        document.getElementById('notifBadge');

    const pill =
        document.getElementById('notifPill');

    const listEl =
        document.getElementById('notifList');

    const btn =
        document.getElementById('bellBtn');

    const isOpen =
        btn?.closest('.dropdown')?.classList.contains('show');


    if (data.unread > 0) {

        if (badge) {

            badge.textContent = data.unread;
            badge.style.display = '';

        } else if (btn) {

            const nb =
                document.createElement('span');

            nb.id = 'notifBadge';

            nb.className = 'notif-badge';

            nb.textContent = data.unread;

            btn.appendChild(nb);
        }


        if (pill) {

            pill.textContent =
                data.unread + ' new';

            pill.classList.add('visible');
        }

    } else {

        if (badge) {

            badge.style.display = 'none';
        }

        if (pill) {

            pill.classList.remove('visible');
        }
    }


    if (!listEl || isOpen) {
        return;
    }


    if (!data.notifications.length) {

        listEl.innerHTML =
            '<div class="notif-empty">' +
                '<i class="fas fa-bell-slash"></i>' +
                '<p>You\'re all caught up</p>' +
            '</div>';

        return;
    }


    listEl.innerHTML =
        data.notifications.map(n => `

            <a
                class="notif-item${n.is_read == 0 ? ' unread' : ''}"
                href="${escHtml(n.link || '#')}"
                onclick="markRead(${n.id},this);return true;"
            >

                <div class="notif-avatar">

                    <i class="fas fa-exclamation"></i>

                </div>


                <div class="notif-body-text">

                    <div class="notif-msg">
                        ${escHtml(n.message)}
                    </div>


                    <div class="notif-ts">

                        <i
                            class="fas fa-clock"
                            style="margin-right:3px;font-size:9px;"
                        ></i>

                        ${fmtDate(n.created_at)}

                    </div>

                </div>


                ${
                    n.is_read == 0
                        ? '<div class="notif-dot-ind"></div>'
                        : ''
                }

            </a>

        `).join('');
}


/* =====================================================
   MARK ONE NOTIFICATION AS READ
===================================================== */

function markRead(id, el) {

    fetch(
        'ajax/notifications.php?action=read&id=' + id
    );


    el.classList.remove('unread');

    el.querySelector(
        '.notif-dot-ind'
    )?.remove();


    const badge =
        document.getElementById('notifBadge');

    const pill =
        document.getElementById('notifPill');


    if (badge) {

        const n =
            parseInt(badge.textContent) - 1;

        if (n <= 0) {

            badge.style.display = 'none';

        } else {

            badge.textContent = n;
        }
    }


    if (pill) {

        const n =
            parseInt(pill.textContent) - 1;

        if (n <= 0) {

            pill.classList.remove('visible');

        } else {

            pill.textContent = n + ' new';
        }
    }
}


/* =====================================================
   MARK ALL NOTIFICATIONS AS READ
===================================================== */

function markAllRead() {

    fetch(
        'ajax/notifications.php?action=read_all'
    );


    document
        .querySelectorAll('#notifList .notif-item')
        .forEach(el => {

            el.classList.remove('unread');

            el.querySelector(
                '.notif-dot-ind'
            )?.remove();

        });


    const badge =
        document.getElementById('notifBadge');

    if (badge) {

        badge.style.display = 'none';
    }


    const pill =
        document.getElementById('notifPill');

    if (pill) {

        pill.classList.remove('visible');
    }
}


/* =====================================================
   NOTIFICATION POLLING
===================================================== */

document.addEventListener(
    'DOMContentLoaded',
    () => {

        fetch(
            'ajax/notifications.php?action=poll'
        )
        .then(r => r.ok ? r.json() : null)
        .then(d => {

            if (d && !d.error) {

                renderNotifs(d);
            }
        });


        setInterval(
            () => {

                fetch(
                    'ajax/notifications.php?action=poll'
                )
                .then(r => r.ok ? r.json() : null)
                .then(d => {

                    if (d && !d.error) {

                        renderNotifs(d);
                    }
                });

            },
            15000
        );

    }
);

</script>


</body>
</html>