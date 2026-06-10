<?php

$config = require __DIR__ . '/db_config.php';

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    global $config;

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $config['host'],
        $config['port'],
        $config['database'],
        $config['charset']
    );

    $pdo = new PDO($dsn, $config['username'], $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    return $pdo;
}

function jsonResponse($data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

function requestData(): array
{
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($contentType, 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    return $_POST;
}

function md5Password(?string $password): ?string
{
    $password = trim((string) $password);
    if ($password === '') {
        return null;
    }

    return md5($password);
}

function fetchLookups(string $type, array $params = []): array
{
    $pdo = db();

    switch ($type) {
        case 'locations':
            return $pdo->query("SELECT loccode, locname FROM mlocation ORDER BY locname")->fetchAll();

        case 'clients':
            return $pdo->query("SELECT clientcode, clientname FROM mclients ORDER BY clientname")->fetchAll();

        case 'dealers':
            $stmt = $pdo->prepare("SELECT dealercode, dealername FROM mdealers WHERE clientcode = :clientcode ORDER BY dealername");
            $stmt->execute(['clientcode' => $params['clientcode'] ?? '']);
            return $stmt->fetchAll();

        case 'truckers':
            return $pdo->query("SELECT truckingcorp_id, truckingcorp FROM m_truckers ORDER BY truckingcorp")->fetchAll();

        case 'modules':
            $stmt = $pdo->prepare("SELECT module, module_description, user_type FROM m_modules WHERE (:user_type = 0 OR user_type = :user_type) ORDER BY module_description");
            $stmt->execute(['user_type' => (int) ($params['user_type'] ?? 0)]);
            return $stmt->fetchAll();
    }

    return [];
}

function validateUser(array $data, bool $isUpdate = false): array
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

function normalizeUserPayload(array $data): array
{
    $userType = (int) ($data['user_type'] ?? 0);

    $payload = [
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

function saveUserAccess(string $userid, array $modules): void
{
    $pdo = db();
    $delete = $pdo->prepare("DELETE FROM m_users_access WHERE userid = :userid");
    $delete->execute(['userid' => $userid]);

    $insert = $pdo->prepare("INSERT INTO m_users_access (userid, module, `view`) VALUES (:userid, :module, :view)");
    foreach ($modules as $module) {
        if (empty($module['module'])) {
            continue;
        }
        $insert->execute([
            'userid' => $userid,
            'module' => $module['module'],
            'view' => !empty($module['view']) ? 1 : 0,
        ]);
    }
}

$action = $_GET['action'] ?? '';

if ($action !== '') {
    try {
        $pdo = db();
        $data = requestData();

        if ($action === 'list') {
            $sql = "SELECT userid, username, fullname, email, regdate, user_type, is_active, loccode, clientcode, dealercode, truckingcorp_id, subcon_id FROM m_users ORDER BY regdate DESC, userid DESC";
            jsonResponse(['data' => $pdo->query($sql)->fetchAll()]);
        }

        if ($action === 'get') {
            $stmt = $pdo->prepare("SELECT * FROM m_users WHERE userid = :userid");
            $stmt->execute(['userid' => $_GET['userid'] ?? '']);
            $user = $stmt->fetch();

            if (!$user) {
                jsonResponse(['message' => 'User not found.'], 404);
            }

            $accessStmt = $pdo->prepare("SELECT module, `view` FROM m_users_access WHERE userid = :userid");
            $accessStmt->execute(['userid' => $user['userid']]);
            $user['access'] = $accessStmt->fetchAll();

            jsonResponse($user);
        }

        if ($action === 'lookups') {
            jsonResponse([
                'locations' => fetchLookups('locations'),
                'clients' => fetchLookups('clients'),
                'dealers' => fetchLookups('dealers', ['clientcode' => $_GET['clientcode'] ?? '']),
                'truckers' => fetchLookups('truckers'),
                'modules' => fetchLookups('modules', ['user_type' => (int) ($_GET['user_type'] ?? 0)]),
            ]);
        }

        if ($action === 'create') {
            $errors = validateUser($data, false);
            if ($errors) {
                jsonResponse(['errors' => $errors], 422);
            }

            $payload = normalizeUserPayload($data);
            $passwordHash = md5Password((string) ($data['password'] ?? ''));

            $stmt = $pdo->prepare("INSERT INTO m_users (username, fullname, password, email, regdate, user_type, is_active, loccode, clientcode, dealercode, truckingcorp_id, subcon_id)
                VALUES (:username, :fullname, :password, :email, NOW(), :user_type, :is_active, :loccode, :clientcode, :dealercode, :truckingcorp_id, :subcon_id)");
            $stmt->execute([
                'username' => $payload['username'],
                'fullname' => $payload['fullname'],
                'password' => $passwordHash,
                'email' => $payload['email'],
                'user_type' => $payload['user_type'],
                'is_active' => $payload['is_active'],
                'loccode' => $payload['loccode'],
                'clientcode' => $payload['clientcode'],
                'dealercode' => $payload['dealercode'],
                'truckingcorp_id' => $payload['truckingcorp_id'],
                'subcon_id' => $payload['subcon_id'],
            ]);

            $userid = (string) $pdo->lastInsertId();
            saveUserAccess($userid, is_array($data['access'] ?? null) ? $data['access'] : []);

            jsonResponse(['message' => 'User created successfully.']);
        }

        if ($action === 'update') {
            $userid = (string) ($data['userid'] ?? '');
            if ($userid === '') {
                jsonResponse(['message' => 'Userid is required.'], 422);
            }

            $errors = validateUser($data, true);
            if ($errors) {
                jsonResponse(['errors' => $errors], 422);
            }

            $payload = normalizeUserPayload($data);
            $passwordHash = md5Password((string) ($data['password'] ?? ''));

            $sql = "UPDATE m_users SET username = :username, fullname = :fullname, email = :email, user_type = :user_type, is_active = :is_active,
                    loccode = :loccode, clientcode = :clientcode, dealercode = :dealercode, truckingcorp_id = :truckingcorp_id, subcon_id = :subcon_id";
            $params = [
                'userid' => $userid,
                'username' => $payload['username'],
                'fullname' => $payload['fullname'],
                'email' => $payload['email'],
                'user_type' => $payload['user_type'],
                'is_active' => $payload['is_active'],
                'loccode' => $payload['loccode'],
                'clientcode' => $payload['clientcode'],
                'dealercode' => $payload['dealercode'],
                'truckingcorp_id' => $payload['truckingcorp_id'],
                'subcon_id' => $payload['subcon_id'],
            ];

            if ($passwordHash !== null) {
                $sql .= ", password = :password";
                $params['password'] = $passwordHash;
            }

            $sql .= " WHERE userid = :userid";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            saveUserAccess($userid, is_array($data['access'] ?? null) ? $data['access'] : []);

            jsonResponse(['message' => 'User updated successfully.']);
        }

        if ($action === 'delete') {
            $userid = (string) ($data['userid'] ?? '');
            if ($userid === '') {
                jsonResponse(['message' => 'Userid is required.'], 422);
            }

            $pdo->beginTransaction();
            $stmtAccess = $pdo->prepare("DELETE FROM m_users_access WHERE userid = :userid");
            $stmtAccess->execute(['userid' => $userid]);
            $stmtUser = $pdo->prepare("DELETE FROM m_users WHERE userid = :userid");
            $stmtUser->execute(['userid' => $userid]);
            $pdo->commit();

            jsonResponse(['message' => 'User deleted successfully.']);
        }

        jsonResponse(['message' => 'Invalid action.'], 400);
    } catch (Throwable $e) {
        if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        jsonResponse(['message' => $e->getMessage()], 500);
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Users CRUD</title>
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
            <small class="text-muted">Single-page PHP CRUD with Bootstrap 5, jQuery, and Tabulator</small>
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
                            <input type="hidden" name="is_active_value" id="is_active_value" value="1">
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
    lookupCache = await fetchJson('users.php?' + params.toString());

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
    const data = await fetchJson('users.php?' + params.toString());
    lookupCache.modules = data.modules || [];
    lookupCache.dealers = data.dealers || [];
    populateSelect('#dealercode', lookupCache.dealers, 'dealercode', item => `${item.dealercode} - ${item.dealername}`, true, 'Select dealer');
    renderAccessTable(lookupCache.modules, accessRows);
}

function resetForm() {
    $('#userForm')[0].reset();
    $('#userid').val('');
    $('#is_active').prop('checked', true);
    $('#is_active_value').val('1');
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
    const user = await fetchJson('users.php?action=get&userid=' + encodeURIComponent(userid));
    await loadLookups(user.user_type || 0, user.clientcode || '');

    $('#userid').val(user.userid);
    $('#username').val(user.username);
    $('#fullname').val(user.fullname);
    $('#email').val(user.email);
    $('#password').val('');
    $('#user_type').val(String(user.user_type));
    $('#is_active').prop('checked', Number(user.is_active) === 1);
    $('#is_active_value').val(Number(user.is_active) === 1 ? '1' : '0');
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
        await fetchJson('users.php?action=' + action, {
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
        await fetchJson('users.php?action=delete', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ userid })
        });
        usersTable.replaceData();
    } catch (error) {
        alert(error.message || 'Unable to delete record.');
    }
}

$(async function () {
    usersTable = new Tabulator('#users-table', {
        ajaxURL: 'users.php?action=list',
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

    $('#is_active').on('change', function () {
        $('#is_active_value').val($(this).is(':checked') ? '1' : '0');
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
