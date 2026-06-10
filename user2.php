<?php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/mysqli.class.php';

function db2()
{
    static $db = null;

    if ($db !== null) {
        return $db;
    }

    if (class_exists('MysqliDb')) {
        if (defined('DBHOST') && defined('DBUSER') && defined('DBPASS') && defined('DBNAME')) {
            $port = defined('DBPORT') ? DBPORT : 3306;
            $charset = defined('DBCHARSET') ? DBCHARSET : 'utf8mb4';
            $db = new MysqliDb(DBHOST, DBUSER, DBPASS, DBNAME, $port, $charset);
            return $db;
        }
    }

    if (class_exists('mysqli')) {
        $host = defined('DBHOST') ? DBHOST : '127.0.0.1';
        $user = defined('DBUSER') ? DBUSER : 'root';
        $pass = defined('DBPASS') ? DBPASS : '';
        $name = defined('DBNAME') ? DBNAME : '';
        $port = defined('DBPORT') ? DBPORT : 3306;
        $db = new mysqli($host, $user, $pass, $name, $port);
        if ($db->connect_error) {
            throw new RuntimeException('Database connection failed: ' . $db->connect_error);
        }
        $charset = defined('DBCHARSET') ? DBCHARSET : 'utf8mb4';
        $db->set_charset($charset);
        return $db;
    }

    throw new RuntimeException('No mysqli driver available.');
}

function jsonResponse2($data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

function requestData2(): array
{
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($contentType, 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    return $_POST;
}

function md5Password2(?string $password): ?string
{
    $password = trim((string) $password);
    if ($password === '') {
        return null;
    }

    return md5($password);
}

function fetchAllAssoc2(mysqli_stmt $stmt): array
{
    $result = $stmt->get_result();
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

function fetchOneAssoc2(mysqli_stmt $stmt): ?array
{
    $result = $stmt->get_result();
    if (!$result) {
        return null;
    }
    $row = $result->fetch_assoc();
    return $row ?: null;
}

function fetchLookups2(string $type, array $params = []): array
{
    $db = db2();

    switch ($type) {
        case 'locations':
            $sql = 'SELECT loccode, locname FROM mlocation ORDER BY locname';
            $result = $db->query($sql);
            return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];

        case 'clients':
            $sql = 'SELECT clientcode, clientname FROM mclients ORDER BY clientname';
            $result = $db->query($sql);
            return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];

        case 'dealers':
            $stmt = $db->prepare('SELECT dealercode, dealername FROM mdealers WHERE clientcode = ? ORDER BY dealername');
            $clientcode = (string) ($params['clientcode'] ?? '');
            $stmt->bind_param('s', $clientcode);
            $stmt->execute();
            $rows = fetchAllAssoc2($stmt);
            $stmt->close();
            return $rows;

        case 'truckers':
            $sql = 'SELECT truckingcorp_id, truckingcorp FROM m_truckers ORDER BY truckingcorp';
            $result = $db->query($sql);
            return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];

        case 'modules':
            $userType = (int) ($params['user_type'] ?? 0);
            if ($userType > 0) {
                $stmt = $db->prepare('SELECT module, module_description, user_type FROM m_modules WHERE user_type = ? ORDER BY module_description');
                $stmt->bind_param('i', $userType);
                $stmt->execute();
                $rows = fetchAllAssoc2($stmt);
                $stmt->close();
                if (!empty($rows)) {
                    return $rows;
                }
            }

            $sql = 'SELECT module, module_description, user_type FROM m_modules ORDER BY module_description';
            $result = $db->query($sql);
            return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    }

    return [];
}

function validateUser2(array $data, bool $isUpdate = false): array
{
    $errors = [];
    $userType = (int) ($data['user_type'] ?? 0);
    $password = (string) ($data['password'] ?? '');

    if (trim((string) ($data['username'] ?? '')) === '') {
        $errors['username'] = 'Username is required.';
    }

    if (trim((string) ($data['fullname'] ?? '')) === '') {
        $errors['fullname'] = 'Fullname is required.';
    }

    if (trim((string) ($data['email'] ?? '')) === '') {
        $errors['email'] = 'Email is required.';
    }

    if (!$isUpdate || trim($password) !== '') {
        if (!ctype_digit($password) || strlen($password) < 6) {
            $errors['password'] = 'Password must be numeric and at least 6 digits.';
        }
    }

    if (!in_array((string) ($data['is_active'] ?? ''), ['0', '1', 0, 1], true)) {
        $errors['is_active'] = 'Active flag is required.';
    }

    if ($userType === 2) {
        if (trim((string) ($data['clientcode'] ?? '')) === '') {
            $errors['clientcode'] = 'Client is required for Client user type.';
        }
        if (trim((string) ($data['dealercode'] ?? '')) === '') {
            $errors['dealercode'] = 'Dealer is required for Client user type.';
        }
    }

    if ($userType === 3) {
        if ((int) ($data['truckingcorp_id'] ?? 0) <= 0) {
            $errors['truckingcorp_id'] = 'Trucking corporation is required for Trucker user type.';
        }
        if ((int) ($data['subcon_id'] ?? 0) <= 0) {
            $errors['subcon_id'] = 'Subcon is required for Trucker user type.';
        }
    }

    return $errors;
}

function normalizeUserPayload2(array $data): array
{
    $userType = (int) ($data['user_type'] ?? 0);

    $payload = [
        'userid' => (string) ($data['userid'] ?? ''),
        'username' => trim((string) ($data['username'] ?? '')),
        'fullname' => trim((string) ($data['fullname'] ?? '')),
        'email' => trim((string) ($data['email'] ?? '')),
        'user_type' => $userType,
        'is_active' => (int) ($data['is_active'] ?? 1),
        'loccode' => trim((string) ($data['loccode'] ?? '')),
        'clientcode' => trim((string) ($data['clientcode'] ?? '')),
        'dealercode' => trim((string) ($data['dealercode'] ?? '')),
        'truckingcorp_id' => (int) ($data['truckingcorp_id'] ?? 0),
        'subcon_id' => (int) ($data['subcon_id'] ?? 0),
    ];

    if ($userType === 2) {
        $payload['truckingcorp_id'] = 0;
        $payload['subcon_id'] = 0;
    }

    if ($userType === 3) {
        $payload['clientcode'] = '';
        $payload['dealercode'] = '';
    }

    return $payload;
}

function getPostedAccess2(array $data): array
{
    return is_array($data['access'] ?? null) ? $data['access'] : [];
}

function saveUserAccess2(string $userid, array $modules): void
{
    $db = db2();

    $stmtDelete = $db->prepare('DELETE FROM m_users_access WHERE userid = ?');
    $stmtDelete->bind_param('s', $userid);
    $stmtDelete->execute();
    $stmtDelete->close();

    $stmtInsert = $db->prepare('INSERT INTO m_users_access (userid, module, `view`) VALUES (?, ?, ?)');
    foreach ($modules as $moduleRow) {
        $module = (string) ($moduleRow['module'] ?? '');
        if ($module === '') {
            continue;
        }
        $view = !empty($moduleRow['view']) ? 1 : 0;
        $stmtInsert->bind_param('ssi', $userid, $module, $view);
        $stmtInsert->execute();
    }
    $stmtInsert->close();
}

$action = $_GET['action'] ?? '';

if ($action !== '') {
    try {
        $db = db2();
        $data = requestData2();

        if ($action === 'list') {
            $sql = 'SELECT userid, username, fullname, email, regdate, user_type, is_active, loccode, clientcode, dealercode, truckingcorp_id, subcon_id FROM m_users ORDER BY regdate DESC, userid DESC';
            $result = $db->query($sql);
            jsonResponse2(['data' => $result ? $result->fetch_all(MYSQLI_ASSOC) : []]);
        }

        if ($action === 'get') {
            $userid = (string) ($_GET['userid'] ?? '');
            $stmt = $db->prepare('SELECT * FROM m_users WHERE userid = ?');
            $stmt->bind_param('s', $userid);
            $stmt->execute();
            $user = fetchOneAssoc2($stmt);
            $stmt->close();

            if (!$user) {
                jsonResponse2(['message' => 'User not found.'], 404);
            }

            $stmtAccess = $db->prepare('SELECT module, `view` FROM m_users_access WHERE userid = ?');
            $stmtAccess->bind_param('s', $userid);
            $stmtAccess->execute();
            $user['access'] = fetchAllAssoc2($stmtAccess);
            $stmtAccess->close();

            jsonResponse2($user);
        }

        if ($action === 'lookups') {
            jsonResponse2([
                'locations' => fetchLookups2('locations'),
                'clients' => fetchLookups2('clients'),
                'dealers' => fetchLookups2('dealers', ['clientcode' => $_GET['clientcode'] ?? '']),
                'truckers' => fetchLookups2('truckers'),
                'modules' => fetchLookups2('modules', ['user_type' => (int) ($_GET['user_type'] ?? 0)]),
            ]);
        }

        if ($action === 'create') {
            $errors = validateUser2($data, false);
            if ($errors) {
                jsonResponse2(['errors' => $errors], 422);
            }

            $payload = normalizeUserPayload2($data);
            $passwordHash = md5Password2((string) ($data['password'] ?? ''));

            $stmt = $db->prepare('INSERT INTO m_users (username, fullname, password, email, regdate, user_type, is_active, loccode, clientcode, dealercode, truckingcorp_id, subcon_id) VALUES (?, ?, ?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?)');
            $stmt->bind_param(
                'ssssissssii',
                $payload['username'],
                $payload['fullname'],
                $passwordHash,
                $payload['email'],
                $payload['user_type'],
                $payload['is_active'],
                $payload['loccode'],
                $payload['clientcode'],
                $payload['dealercode'],
                $payload['truckingcorp_id'],
                $payload['subcon_id']
            );
            $stmt->execute();
            $userid = (string) $db->insert_id;
            $stmt->close();

            saveUserAccess2($userid, getPostedAccess2($data));
            jsonResponse2(['message' => 'User created successfully.']);
        }

        if ($action === 'update') {
            $payload = normalizeUserPayload2($data);
            if ($payload['userid'] === '') {
                jsonResponse2(['message' => 'Userid is required.'], 422);
            }

            $errors = validateUser2($data, true);
            if ($errors) {
                jsonResponse2(['errors' => $errors], 422);
            }

            $passwordHash = md5Password2((string) ($data['password'] ?? ''));

            if ($passwordHash !== null) {
                $stmt = $db->prepare('UPDATE m_users SET username = ?, fullname = ?, email = ?, user_type = ?, is_active = ?, loccode = ?, clientcode = ?, dealercode = ?, truckingcorp_id = ?, subcon_id = ?, password = ? WHERE userid = ?');
                $stmt->bind_param(
                    'sssiisssiiss',
                    $payload['username'],
                    $payload['fullname'],
                    $payload['email'],
                    $payload['user_type'],
                    $payload['is_active'],
                    $payload['loccode'],
                    $payload['clientcode'],
                    $payload['dealercode'],
                    $payload['truckingcorp_id'],
                    $payload['subcon_id'],
                    $passwordHash,
                    $payload['userid']
                );
            } else {
                $stmt = $db->prepare('UPDATE m_users SET username = ?, fullname = ?, email = ?, user_type = ?, is_active = ?, loccode = ?, clientcode = ?, dealercode = ?, truckingcorp_id = ?, subcon_id = ? WHERE userid = ?');
                $stmt->bind_param(
                    'sssiisssiis',
                    $payload['username'],
                    $payload['fullname'],
                    $payload['email'],
                    $payload['user_type'],
                    $payload['is_active'],
                    $payload['loccode'],
                    $payload['clientcode'],
                    $payload['dealercode'],
                    $payload['truckingcorp_id'],
                    $payload['subcon_id'],
                    $payload['userid']
                );
            }
            $stmt->execute();
            $stmt->close();

            saveUserAccess2($payload['userid'], getPostedAccess2($data));
            jsonResponse2(['message' => 'User updated successfully.']);
        }

        if ($action === 'delete') {
            $userid = (string) ($data['userid'] ?? '');
            if ($userid === '') {
                jsonResponse2(['message' => 'Userid is required.'], 422);
            }

            $db->begin_transaction();

            $stmtAccess = $db->prepare('DELETE FROM m_users_access WHERE userid = ?');
            $stmtAccess->bind_param('s', $userid);
            $stmtAccess->execute();
            $stmtAccess->close();

            $stmtUser = $db->prepare('DELETE FROM m_users WHERE userid = ?');
            $stmtUser->bind_param('s', $userid);
            $stmtUser->execute();
            $stmtUser->close();

            $db->commit();
            jsonResponse2(['message' => 'User deleted successfully.']);
        }

        jsonResponse2(['message' => 'Invalid action.'], 400);
    } catch (Throwable $e) {
        if (isset($db) && $db instanceof mysqli && $db->errno === 0) {
            // no-op fallback
        }
        jsonResponse2(['message' => $e->getMessage()], 500);
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Users CRUD - mysqli</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
    <link href="https://unpkg.com/tabulator-tables@6.4.0/dist/css/tabulator_bootstrap5.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <script src="https://unpkg.com/tabulator-tables@6.4.0/dist/js/tabulator.min.js"></script>
    <style>
        body { background: #f8f9fa; }
        .tabulator { font-size: 0.95rem; }
        .section-card { box-shadow: 0 0.125rem 0.25rem rgba(0,0,0,.075); }
        .access-table th, .access-table td { vertical-align: middle; }
        .field-hidden { display: none; }
    </style>
</head>
<body>
<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h2 class="mb-0">Users Management</h2>
            <small class="text-muted">mysqli version using config.php and mysqli.class.php</small>
        </div>
        <div class="btn-group">
            <button class="btn btn-success" id="btnAdd"><i class="fa fa-plus"></i> Add User</button>
            <button class="btn btn-outline-secondary" id="btnCsv"><i class="fa fa-file-csv"></i> CSV</button>
            <button class="btn btn-outline-secondary" id="btnXlsx"><i class="fa fa-file-excel"></i> Excel</button>
        </div>
    </div>

    <div class="card section-card">
        <div class="card-body">
            <div id="users-table"></div>
        </div>
    </div>
</div>

<div class="modal fade" id="userModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <form id="userForm">
                <div class="modal-header">
                    <h5 class="modal-title">User</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="userid" id="userid">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Username</label>
                            <input type="text" class="form-control" name="username" id="username" maxlength="20" required>
                            <div class="invalid-feedback" data-error-for="username"></div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Fullname</label>
                            <input type="text" class="form-control" name="fullname" id="fullname" maxlength="50" required>
                            <div class="invalid-feedback" data-error-for="fullname"></div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Email</label>
                            <input type="email" class="form-control" name="email" id="email" maxlength="50" required>
                            <div class="invalid-feedback" data-error-for="email"></div>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Password</label>
                            <div class="input-group">
                                <input type="password" class="form-control" name="password" id="password" inputmode="numeric" autocomplete="new-password">
                                <button class="btn btn-outline-secondary" type="button" id="togglePassword"><i class="fa fa-eye"></i></button>
                            </div>
                            <div class="form-text">Numeric only, minimum 6 digits. Leave blank on edit to keep current password.</div>
                            <div class="invalid-feedback d-block" data-error-for="password"></div>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">User Type</label>
                            <select class="form-select" name="user_type" id="user_type" required>
                                <option value="">Select type</option>
                                <option value="1">Company</option>
                                <option value="2">Client</option>
                                <option value="3">Trucker</option>
                            </select>
                        </div>

                        <div class="col-md-4 d-flex align-items-end">
                            <div class="form-check form-switch mt-3">
                                <input class="form-check-input" type="checkbox" id="is_active" checked>
                                <label class="form-check-label" for="is_active">Active</label>
                            </div>
                            <div class="invalid-feedback d-block" data-error-for="is_active"></div>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Location</label>
                            <select class="form-select" name="loccode" id="loccode">
                                <option value="">Select location</option>
                            </select>
                        </div>

                        <div class="col-md-4 client-field">
                            <label class="form-label">Client</label>
                            <select class="form-select" name="clientcode" id="clientcode">
                                <option value="">Select client</option>
                            </select>
                            <div class="invalid-feedback" data-error-for="clientcode"></div>
                        </div>

                        <div class="col-md-4 dealer-field">
                            <label class="form-label">Dealer</label>
                            <select class="form-select" name="dealercode" id="dealercode">
                                <option value="">Select dealer</option>
                            </select>
                            <div class="invalid-feedback" data-error-for="dealercode"></div>
                        </div>

                        <div class="col-md-4 trucker-field">
                            <label class="form-label">Trucking Corporation</label>
                            <select class="form-select" name="truckingcorp_id" id="truckingcorp_id">
                                <option value="0">Select trucking corporation</option>
                            </select>
                            <div class="invalid-feedback" data-error-for="truckingcorp_id"></div>
                        </div>

                        <div class="col-md-4 trucker-field">
                            <label class="form-label">Subcon</label>
                            <select class="form-select" name="subcon_id" id="subcon_id">
                                <option value="0">Select subcon</option>
                            </select>
                            <div class="invalid-feedback" data-error-for="subcon_id"></div>
                        </div>
                    </div>

                    <hr class="my-4">
                    <h6>Module Access</h6>
                    <div class="table-responsive">
                        <table class="table table-bordered table-sm access-table mb-0" id="accessTable">
                            <thead class="table-light">
                                <tr>
                                    <th style="width: 30%;">Module</th>
                                    <th>Description</th>
                                    <th style="width: 120px;" class="text-center">View</th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-primary"><i class="fa fa-save"></i> Save</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
const userModal = new bootstrap.Modal(document.getElementById('userModal'));
let usersTable;
let lookupCache = { locations: [], clients: [], dealers: [], truckers: [], modules: [] };

function escapeHtml(value) {
    return $('<div>').text(value ?? '').html();
}

function showErrors(errors = {}) {
    $('[data-error-for]').text('');
    $('.is-invalid').removeClass('is-invalid');
    Object.entries(errors).forEach(([field, message]) => {
        $('[data-error-for="' + field + '"]').text(message);
        const el = $('#' + field);
        if (el.length) {
            el.addClass('is-invalid');
        }
    });
}

function populateSelect(selector, items, valueKey, labelBuilder, includeBlank = true, blankLabel = 'Select option') {
    const $select = $(selector);
    const current = $select.val();
    $select.empty();
    if (includeBlank) {
        $select.append(`<option value="">${blankLabel}</option>`);
    }
    items.forEach(item => {
        $select.append(`<option value="${item[valueKey]}">${labelBuilder(item)}</option>`);
    });
    if (current !== null) {
        $select.val(current);
    }
}

function populateNumericSelect(selector, items, valueKey, labelBuilder, zeroLabel) {
    const $select = $(selector);
    const current = $select.val();
    $select.empty().append(`<option value="0">${zeroLabel}</option>`);
    items.forEach(item => {
        $select.append(`<option value="${item[valueKey]}">${labelBuilder(item)}</option>`);
    });
    if (current !== null) {
        $select.val(current);
    }
}

async function fetchJson(url, options = {}) {
    const response = await fetch(url, options);
    const data = await response.json();
    if (!response.ok) {
        throw data;
    }
    return data;
}

async function loadLookups(userType = 0, clientcode = '') {
    const params = new URLSearchParams({ action: 'lookups', user_type: userType, clientcode });
    lookupCache = await fetchJson('user2.php?' + params.toString());

    populateSelect('#loccode', lookupCache.locations, 'loccode', item => `${item.loccode} - ${item.locname}`, true, 'Select location');
    populateSelect('#clientcode', lookupCache.clients, 'clientcode', item => `${item.clientcode} - ${item.clientname}`, true, 'Select client');
    populateSelect('#dealercode', lookupCache.dealers, 'dealercode', item => `${item.dealercode} - ${item.dealername}`, true, 'Select dealer');
    populateNumericSelect('#truckingcorp_id', lookupCache.truckers, 'truckingcorp_id', item => `${item.truckingcorp_id} - ${item.truckingcorp}`, 'Select trucking corporation');
    populateNumericSelect('#subcon_id', lookupCache.truckers, 'truckingcorp_id', item => `${item.truckingcorp_id} - ${item.truckingcorp}`, 'Select subcon');
    renderAccessTable(lookupCache.modules, []);
}

function renderAccessTable(modules, selectedAccess) {
    const selectedMap = {};
    (selectedAccess || []).forEach(row => {
        selectedMap[row.module] = Number(row.view) === 1;
    });

    const rows = modules.map(row => `
        <tr>
            <td>${escapeHtml(row.module)}</td>
            <td>${escapeHtml(row.module_description ?? row.module)}</td>
            <td class="text-center">
                <input type="checkbox" class="form-check-input access-view" data-module="${escapeHtml(row.module)}" ${selectedMap[row.module] ? 'checked' : ''}>
            </td>
        </tr>
    `).join('');

    $('#accessTable tbody').html(rows || '<tr><td colspan="3" class="text-center text-muted">No modules available.</td></tr>');
}

function getAccessPayload() {
    return $('#accessTable tbody .access-view').map(function () {
        return {
            module: $(this).data('module'),
            view: $(this).is(':checked') ? 1 : 0
        };
    }).get();
}

function applyUserTypeRules() {
    const userType = Number($('#user_type').val() || 0);

    $('.client-field, .dealer-field, .trucker-field').removeClass('field-hidden');
    $('.dealer-field').toggleClass('field-hidden', !(userType === 2));
    $('.trucker-field').toggleClass('field-hidden', !(userType === 3));

    if (userType === 1) {
        $('.client-field').removeClass('field-hidden');
        $('#dealercode').val('');
        $('#truckingcorp_id').val('0');
        $('#subcon_id').val('0');
    }

    if (userType === 2) {
        $('.client-field, .dealer-field').removeClass('field-hidden');
        $('#truckingcorp_id').val('0');
        $('#subcon_id').val('0');
    }

    if (userType === 3) {
        $('.client-field, .dealer-field').addClass('field-hidden');
        $('#clientcode').val('');
        $('#dealercode').val('');
    }
}

async function refreshModules(accessRows = []) {
    const userType = Number($('#user_type').val() || 0);
    const params = new URLSearchParams({ action: 'lookups', user_type: userType, clientcode: $('#clientcode').val() || '' });
    const data = await fetchJson('user2.php?' + params.toString());
    lookupCache.modules = data.modules || [];
    lookupCache.dealers = data.dealers || [];
    populateSelect('#dealercode', lookupCache.dealers, 'dealercode', item => `${item.dealercode} - ${item.dealername}`, true, 'Select dealer');
    renderAccessTable(lookupCache.modules, accessRows);
}

function resetForm() {
    $('#userForm')[0].reset();
    $('#userid').val('');
    $('#is_active').prop('checked', true);
    showErrors({});
    applyUserTypeRules();
}

async function openCreateModal() {
    resetForm();
    await loadLookups(0, '');
    userModal.show();
}

async function openEditModal(userid) {
    resetForm();
    const user = await fetchJson('user2.php?action=get&userid=' + encodeURIComponent(userid));
    await loadLookups(user.user_type || 0, user.clientcode || '');

    $('#userid').val(user.userid);
    $('#username').val(user.username);
    $('#fullname').val(user.fullname);
    $('#email').val(user.email);
    $('#password').val('');
    $('#user_type').val(String(user.user_type));
    $('#is_active').prop('checked', Number(user.is_active) === 1);
    $('#loccode').val(user.loccode || '');
    $('#clientcode').val(user.clientcode || '');

    if (user.clientcode) {
        await refreshModules(user.access || []);
        $('#dealercode').val(user.dealercode || '');
    } else {
        renderAccessTable(lookupCache.modules, user.access || []);
        $('#dealercode').val(user.dealercode || '');
    }

    $('#truckingcorp_id').val(String(user.truckingcorp_id || 0));
    $('#subcon_id').val(String(user.subcon_id || 0));
    applyUserTypeRules();
    renderAccessTable(lookupCache.modules, user.access || []);
    userModal.show();
}

async function saveUser(event) {
    event.preventDefault();
    showErrors({});

    const payload = {
        userid: $('#userid').val(),
        username: $('#username').val().trim(),
        fullname: $('#fullname').val().trim(),
        password: $('#password').val().trim(),
        email: $('#email').val().trim(),
        user_type: $('#user_type').val(),
        is_active: $('#is_active').is(':checked') ? 1 : 0,
        loccode: $('#loccode').val() || '',
        clientcode: $('#clientcode').val() || '',
        dealercode: $('#dealercode').val() || '',
        truckingcorp_id: $('#truckingcorp_id').val() || 0,
        subcon_id: $('#subcon_id').val() || 0,
        access: getAccessPayload()
    };

    const action = payload.userid ? 'update' : 'create';

    try {
        await fetchJson('user2.php?action=' + action, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        userModal.hide();
        usersTable.replaceData();
    } catch (error) {
        if (error.errors) {
            showErrors(error.errors);
            return;
        }
        alert(error.message || 'Unable to save record.');
    }
}

async function deleteUser(userid, username) {
    if (!confirm(`Delete user "${username}"?`)) {
        return;
    }

    try {
        await fetchJson('user2.php?action=delete', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ userid })
        });
        usersTable.replaceData();
    } catch (error) {
        alert(error.message || 'Unable to delete record.');
    }
}

$(function () {
    usersTable = new Tabulator('#users-table', {
        ajaxURL: 'user2.php?action=list',
        ajaxResponse: function (url, params, response) { return response.data || []; },
        layout: 'fitColumns',
        pagination: true,
        paginationSize: 10,
        height: '550px',
        placeholder: 'No users found',
        columns: [
            { title: 'User ID', field: 'userid', width: 90 },
            { title: 'Username', field: 'username' },
            { title: 'Fullname', field: 'fullname' },
            { title: 'Email', field: 'email' },
            { title: 'Type', field: 'user_type', formatter: cell => ({1:'Company',2:'Client',3:'Trucker'})[cell.getValue()] || '' },
            { title: 'Active', field: 'is_active', hozAlign: 'center', formatter: cell => Number(cell.getValue()) === 1 ? 'Yes' : 'No' },
            { title: 'Registered', field: 'regdate' },
            {
                title: 'Actions',
                hozAlign: 'center',
                width: 120,
                formatter: () => '<button class="btn btn-sm btn-primary me-1 edit-row"><i class="fa fa-pen"></i></button><button class="btn btn-sm btn-danger delete-row"><i class="fa fa-trash"></i></button>',
                cellClick: function (e, cell) {
                    const row = cell.getRow().getData();
                    if ($(e.target).closest('.edit-row').length) {
                        openEditModal(row.userid);
                    }
                    if ($(e.target).closest('.delete-row').length) {
                        deleteUser(row.userid, row.username);
                    }
                }
            }
        ]
    });

    $('#btnAdd').on('click', openCreateModal);
    $('#btnCsv').on('click', () => usersTable.download('csv', 'users.csv'));
    $('#btnXlsx').on('click', () => usersTable.download('xlsx', 'users.xlsx', { sheetName: 'Users' }));
    $('#userForm').on('submit', saveUser);

    $('#togglePassword').on('click', function () {
        const input = $('#password');
        const isPassword = input.attr('type') === 'password';
        input.attr('type', isPassword ? 'text' : 'password');
        $(this).html(isPassword ? '<i class="fa fa-eye-slash"></i>' : '<i class="fa fa-eye"></i>');
    });

    $('#user_type').on('change', async function () {
        applyUserTypeRules();
        await refreshModules([]);
    });

    $('#clientcode').on('change', async function () {
        const existingAccess = getAccessPayload();
        await refreshModules(existingAccess);
    });
});
</script>
</body>
</html>
