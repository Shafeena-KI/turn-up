<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>License Manager</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; }
        .container { max-width: 1000px; }
        .card { border: none; border-radius: 15px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); }
        .card-header { background: linear-gradient(45deg, #667eea, #764ba2); color: white; border-radius: 15px 15px 0 0 !important; }
        .btn-primary { background: linear-gradient(45deg, #667eea, #764ba2); border: none; }
        .btn-danger { background: linear-gradient(45deg, #ff6b6b, #ee5a52); border: none; }
        .btn-success { background: linear-gradient(45deg, #51cf66, #40c057); border: none; }
        .form-control, .form-select { border-radius: 10px; border: 2px solid #e9ecef; }
        .form-control:focus, .form-select:focus { border-color: #667eea; box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25); }
        .alert { border-radius: 10px; }
        .user-card { background: #f8f9fa; border-radius: 10px; border-left: 4px solid #667eea; }
        .status-active { color: #28a745; font-weight: bold; }
        .status-revoked { color: #dc3545; font-weight: bold; }
        .status-none { color: #6c757d; font-weight: bold; }
        .login-card { max-width: 500px; margin: 0 auto; margin-top: 10vh; }
    </style>
</head>
<body>
    <div class="container py-4">
        <!-- Private Key Verification -->
        <div id="loginSection" class="login-card">
            <div class="card">
                <div class="card-header text-center">
                    <h4 class="mb-0"><i class="fas fa-lock"></i> Secure Access</h4>
                    <p class="mb-0 mt-2 opacity-75">Enter your private key to access License Manager</p>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label"><i class="fas fa-key"></i> Private Key</label>
                        <textarea class="form-control" id="loginPrivateKey" rows="8" placeholder="-----BEGIN PRIVATE KEY-----&#10;...&#10;-----END PRIVATE KEY-----"></textarea>
                    </div>
                    <div class="d-grid">
                        <button class="btn btn-primary btn-lg" onclick="verifyAccess()">
                            <i class="fas fa-unlock"></i> Verify & Access
                        </button>
                    </div>
                    <div id="loginResult" class="mt-3" style="display:none;"></div>
                </div>
            </div>
        </div>

        <!-- Main License Manager (Hidden Initially) -->
        <div id="managerSection" style="display:none;">
            <div class="text-center mb-4">
                <h1 class="text-white"><i class="fas fa-shield-alt"></i> License Manager</h1>
                <p class="text-white-50">Manage user licenses with ease</p>
                <button class="btn btn-outline-light btn-sm" onclick="logout()"><i class="fas fa-sign-out-alt"></i> Logout</button>
            </div>
            
            <!-- Quick Actions -->
            <div class="row mb-4">
                <div class="col-md-4">
                    <div class="card text-center">
                        <div class="card-body">
                            <i class="fas fa-users fa-2x text-success mb-2"></i>
                            <h6>Grant All Users</h6>
                            <button class="btn btn-success btn-sm" onclick="quickGrant()">Grant</button>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card text-center">
                        <div class="card-body">
                            <i class="fas fa-user-times fa-2x text-danger mb-2"></i>
                            <h6>Revoke All Users</h6>
                            <button class="btn btn-danger btn-sm" onclick="quickRevoke()">Revoke</button>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card text-center">
                        <div class="card-body">
                            <i class="fas fa-list fa-2x text-info mb-2"></i>
                            <h6>Check All Users</h6>
                            <button class="btn btn-info btn-sm" onclick="checkAllLicenses()">Check</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Main Interface -->
            <div class="card">
                <div class="card-header">
                    <h4 class="mb-0"><i class="fas fa-cogs"></i> License Operations</h4>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label"><i class="fas fa-tasks"></i> Operation</label>
                                <select class="form-select" id="operation" onchange="updateUI()">
                                    <option value="grant">Grant Access</option>
                                    <option value="revoke">Revoke Access</option>
                                    <option value="check">Check License</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label"><i class="fas fa-user"></i> Target</label>
                                <select class="form-select" id="target" onchange="updateUI()">
                                    <option value="single">Single User</option>
                                    <option value="all">All Users</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div id="clientIdGroup" class="mb-3">
                        <label class="form-label"><i class="fas fa-id-badge"></i> Client ID</label>
                        <input type="number" class="form-control" id="clientId" placeholder="Enter client ID">
                    </div>

                    <div id="expiryGroup" class="mb-3">
                        <label class="form-label"><i class="fas fa-calendar"></i> Expiry Date</label>
                        <input type="date" class="form-control" id="expiryDate">
                    </div>

                    <div id="reasonGroup" class="mb-3" style="display:none;">
                        <label class="form-label"><i class="fas fa-comment"></i> Reason</label>
                        <select class="form-select" id="reasonType">
                            <option value="default">Default</option>
                            <option value="violation">Policy Violation</option>
                            <option value="maintenance">System Maintenance</option>
                            <option value="expired">License Expired</option>
                        </select>
                    </div>

                    <div class="d-grid">
                        <button class="btn btn-primary btn-lg" id="executeBtn" onclick="executeOperation()">
                            <i class="fas fa-play"></i> Execute Operation
                        </button>
                    </div>
                </div>
            </div>

            <!-- Results -->
            <div id="results" class="mt-4" style="display:none;"></div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const API_BASE = 'https://dodgerblue-dogfish-415708.hostingersite.com/turnupeventadmin/backend/api/license';
        let verifiedPrivateKey = null;

        async function verifyAccess() {
            const privateKey = document.getElementById('loginPrivateKey').value;
            
            if (!privateKey) {
                showLoginResult('Private key is required', 'danger');
                return;
            }

            try {
                const response = await fetch('/license-manager/verify-key', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ private_key: privateKey })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    verifiedPrivateKey = privateKey;
                    document.getElementById('loginSection').style.display = 'none';
                    document.getElementById('managerSection').style.display = 'block';
                    updateUI();
                } else {
                    showLoginResult(result.message, 'danger');
                }
            } catch (error) {
                showLoginResult('Verification failed: ' + error.message, 'danger');
            }
        }

        function logout() {
            verifiedPrivateKey = null;
            document.getElementById('loginSection').style.display = 'block';
            document.getElementById('managerSection').style.display = 'none';
            document.getElementById('loginPrivateKey').value = '';
            document.getElementById('results').style.display = 'none';
        }

        function updateUI() {
            const operation = document.getElementById('operation').value;
            const target = document.getElementById('target').value;
            const clientIdGroup = document.getElementById('clientIdGroup');
            const expiryGroup = document.getElementById('expiryGroup');
            const reasonGroup = document.getElementById('reasonGroup');
            const executeBtn = document.getElementById('executeBtn');

            clientIdGroup.style.display = target === 'single' ? 'block' : 'none';
            
            if (operation === 'grant') {
                expiryGroup.style.display = 'block';
                reasonGroup.style.display = 'none';
                executeBtn.className = 'btn btn-success btn-lg';
                executeBtn.innerHTML = '<i class="fas fa-check"></i> Grant Access';
            } else if (operation === 'revoke') {
                expiryGroup.style.display = 'none';
                reasonGroup.style.display = 'block';
                executeBtn.className = 'btn btn-danger btn-lg';
                executeBtn.innerHTML = '<i class="fas fa-times"></i> Revoke Access';
            } else {
                expiryGroup.style.display = 'none';
                reasonGroup.style.display = 'none';
                executeBtn.className = 'btn btn-info btn-lg';
                executeBtn.innerHTML = '<i class="fas fa-search"></i> Check License';
            }
        }

        function quickGrant() {
            document.getElementById('operation').value = 'grant';
            document.getElementById('target').value = 'all';
            updateUI();
        }

        function quickRevoke() {
            document.getElementById('operation').value = 'revoke';
            document.getElementById('target').value = 'all';
            updateUI();
        }

        async function executeOperation() {
            const operation = document.getElementById('operation').value;
            const target = document.getElementById('target').value;
            const clientId = document.getElementById('clientId').value;
            const expiryDate = document.getElementById('expiryDate').value;
            const reasonType = document.getElementById('reasonType').value;

            if (operation === 'check') {
                return await checkLicense(target === 'all' ? null : clientId);
            }

            if (operation === 'grant' && !expiryDate) {
                showAlert('Expiry date is required for grant operation', 'danger');
                return;
            }

            try {
                const sigPayload = { action: operation, private_key: verifiedPrivateKey };
                if (operation === 'grant') sigPayload.expiry_date = expiryDate;
                if (target === 'single' && clientId) sigPayload.client_id = parseInt(clientId);

                const sigResponse = await fetch(`${API_BASE}/generate-signature`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(sigPayload)
                });
                const sigResult = await sigResponse.json();

                if (!sigResult.success) {
                    showAlert(sigResult.message, 'danger');
                    return;
                }

                const opPayload = {
                    signature: sigResult.signature,
                    timestamp: sigResult.timestamp
                };
                
                if (target === 'single' && clientId) opPayload.client_id = parseInt(clientId);
                if (operation === 'grant') opPayload.expiry_date = expiryDate;
                if (operation === 'revoke') opPayload.reason_type = reasonType;

                const endpoint = operation === 'grant' ? 'grant-access' : 'revoke-access';
                const opResponse = await fetch(`${API_BASE}/${endpoint}`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(opPayload)
                });
                const opResult = await opResponse.json();

                if (opResult.success) {
                    let message = opResult.message;
                    if (opResult.processed_count) message += ` (${opResult.processed_count} users processed)`;
                    showAlert(message, 'success');
                } else {
                    showAlert(opResult.message, 'danger');
                }
            } catch (error) {
                showAlert('Error: ' + error.message, 'danger');
            }
        }

        async function checkLicense(clientId) {
            try {
                const payload = clientId ? { client_id: parseInt(clientId) } : {};
                const response = await fetch(`${API_BASE}/check-license`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const result = await response.json();

                if (result.success) {
                    if (result.licenses) {
                        showUsersList(result.licenses, result.total_users);
                    } else {
                        showAlert(`License Status: ${result.license_status || 'No License'}`, 'info');
                    }
                } else {
                    showAlert(result.message, 'danger');
                }
            } catch (error) {
                showAlert('Error: ' + error.message, 'danger');
            }
        }

        async function checkAllLicenses() {
            await checkLicense(null);
        }

        function showLoginResult(message, type) {
            const element = document.getElementById('loginResult');
            element.innerHTML = `<div class="alert alert-${type}">${message}</div>`;
            element.style.display = 'block';
        }

        function showAlert(message, type) {
            const results = document.getElementById('results');
            results.innerHTML = `<div class="alert alert-${type} alert-dismissible fade show" role="alert">
                <i class="fas fa-${type === 'success' ? 'check-circle' : type === 'danger' ? 'exclamation-triangle' : 'info-circle'}"></i>
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>`;
            results.style.display = 'block';
        }

        function showUsersList(licenses, total) {
            let html = `<div class="card"><div class="card-header"><h5><i class="fas fa-users"></i> Users License Status (${total} users)</h5></div><div class="card-body"><div class="row">`;
            
            licenses.forEach(license => {
                const status = license.license_status || 'No License';
                const statusClass = status === 'Active' ? 'status-active' : status === 'Revoked' ? 'status-revoked' : 'status-none';
                const expiry = license.license_expiry ? `<small class="text-muted">Expires: ${license.license_expiry}</small>` : '';
                
                html += `<div class="col-md-6 mb-3">
                    <div class="user-card p-3">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <h6 class="mb-1"><i class="fas fa-user"></i> ${license.name || 'N/A'}</h6>
                                <p class="mb-1 text-muted small">${license.email || 'N/A'}</p>
                                <span class="badge bg-secondary">ID: ${license.client_id}</span>
                            </div>
                            <div class="text-end">
                                <div class="${statusClass}">${status}</div>
                                ${expiry}
                            </div>
                        </div>
                    </div>
                </div>`;
            });
            
            html += '</div></div></div>';
            
            const results = document.getElementById('results');
            results.innerHTML = html;
            results.style.display = 'block';
        }
    </script>
</body>
</html>