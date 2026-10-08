<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth-check.php';
requireRole(['student']);
$pageTitle = 'My Profile';
$currentPage = 'profile.php';

$stu = $pdo->prepare("SELECT s.*, u.name, u.email, u.phone FROM students s JOIN users u ON u.id=s.user_id WHERE u.id=?");
$stu->execute([$_SESSION['user_id']]);
$student = $stu->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $formAction = $_POST['form_action'] ?? '';

    if ($formAction === 'update_profile') {
        $name = trim($_POST['name'] ?? '');
        $email = trim(strtolower($_POST['email'] ?? ''));
        $phone = trim($_POST['phone'] ?? '');
        $address = trim($_POST['address'] ?? '');

        // Auto-complete @gmail.com if student typed name+number without @
        if ($email !== '' && !str_contains($email, '@')) {
            $email .= '@gmail.com';
        }

        if ($name === '') {
            setFlash('error', 'Name cannot be empty.');
        } elseif ($email === '') {
            setFlash('error', 'Invalid email ID: Email ID is required.');
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || !preg_match('/^[a-z0-9._%+-]+@gmail\.com$/i', $email)) {
            setFlash('error', 'Invalid email ID: Email must be a valid @gmail.com address (e.g. name+number@gmail.com).');
        } elseif ($phone === '') {
            setFlash('error', 'Invalid number: Mobile number cannot be empty.');
        } elseif (!preg_match('/^[0-9]+$/', $phone)) {
            setFlash('error', 'Invalid number: Characters or symbols are not allowed. Only numbers (0-9) are permitted.');
        } elseif (strlen($phone) > 10) {
            setFlash('error', 'Invalid number: More than 10 numbers entered (' . strlen($phone) . ' digits). Mobile number must be exactly 10 integers.');
        } elseif (strlen($phone) < 10) {
            setFlash('error', 'Invalid number: Mobile number must be exactly 10 integers (currently ' . strlen($phone) . ' digits).');
        } else {
            // Check email uniqueness among other users
            $chk = $pdo->prepare("SELECT id FROM users WHERE email=? AND id!=?");
            $chk->execute([$email, $_SESSION['user_id']]);
            if ($chk->fetch()) {
                setFlash('error', 'That @gmail.com email is already registered to another user.');
            } else {
                $pdo->prepare("UPDATE users SET name=?, email=?, phone=? WHERE id=?")->execute([$name, $email, $phone, $_SESSION['user_id']]);
                $pdo->prepare("UPDATE students SET address=? WHERE user_id=?")->execute([$address, $_SESSION['user_id']]);
                $_SESSION['name'] = $name;
                $_SESSION['email'] = $email;
                setFlash('success', 'Profile updated successfully.');
            }
        }
    } elseif ($formAction === 'change_password') {
        $current = $_POST['current_password'] ?? '';
        $new = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        $u = $pdo->prepare("SELECT password FROM users WHERE id=?");
        $u->execute([$_SESSION['user_id']]);
        $hash = $u->fetchColumn();

        if (!password_verify($current, $hash)) {
            setFlash('error', 'Current password is incorrect.');
        } elseif (strlen($new) < 8) {
            setFlash('error', 'New password must be at least 8 characters.');
        } elseif ($new !== $confirm) {
            setFlash('error', 'New passwords do not match.');
        } else {
            $pdo->prepare("UPDATE users SET password=? WHERE id=?")->execute([password_hash($new, PASSWORD_DEFAULT), $_SESSION['user_id']]);
            setFlash('success', 'Password changed successfully.');
        }
    }
    redirect('profile.php');
}

include __DIR__ . '/../includes/header.php';
?>
<div class="grid grid-2">
    <div class="card">
        <h3>Profile Details</h3>
        <form method="POST" action="profile.php" id="profileForm" novalidate>
            <?= csrfField() ?>
            <input type="hidden" name="form_action" value="update_profile">
            <div class="form-group"><label>Full Name</label><input class="form-control" name="name" id="nameInput" value="<?= e($student['name']) ?>" required></div>
            
            <!-- Email Field with @gmail.com auto-complete and validation -->
            <div class="form-group" style="position:relative;">
                <label for="emailInput" style="display:flex; justify-content:space-between; align-items:center; font-weight:600;">
                    <span>Email ID <span style="font-size:12px; font-weight:normal; color:var(--text-muted);">(must be @gmail.com)</span> <span style="color:#dc2626;">*</span></span>
                    <button type="button" id="btnAutoGenerateEmail" style="background:#f1f5f9; border:1px solid #cbd5e1; border-radius:4px; color:#4338ca; font-size:11.5px; font-weight:600; cursor:pointer; padding:2px 8px; display:inline-flex; align-items:center; gap:4px;">
                        <i class="fa-solid fa-wand-magic-sparkles"></i> Auto-fill name+number
                    </button>
                </label>
                <div style="position:relative;">
                    <input class="form-control" type="text" name="email" id="emailInput"
                           value="<?= e($student['email']) ?>"
                           placeholder="Enter name+number (e.g. pavan61) or full @gmail.com"
                           autocomplete="email"
                           style="padding-right: 42px; font-size:15px; letter-spacing:0.3px; font-weight:500;"
                           required>
                    <span id="emailStatusIcon" style="position:absolute; right:12px; top:50%; transform:translateY(-50%); font-size:16px; pointer-events:none;"></span>
                </div>

                <!-- Auto-complete suggestion button -->
                <div id="emailSuggestionRow" style="display:none; margin-top:6px; font-size:12px; align-items:center; gap:6px;">
                    <span style="color:#64748b; font-weight:500;">Suggestion:</span>
                    <button type="button" id="applyEmailSuggestion" style="background:#eff6ff; border:1px solid #bfdbfe; color:#1d4ed8; border-radius:14px; padding:2px 10px; font-size:12px; font-weight:600; cursor:pointer; display:inline-flex; align-items:center; gap:5px; transition:all 0.15s ease;">
                        <i class="fa-brands fa-google" style="color:#ea4335;"></i>
                        <span id="suggestedEmailText"></span>
                        <span style="color:#2563eb; font-size:11px;">(Click to apply @gmail.com)</span>
                    </button>
                </div>

                <!-- Real-time Email Error Alert Box -->
                <div id="emailErrorBox" style="display:none; margin-top:8px; padding:10px 14px; background:#fef2f2; border:1px solid #f87171; border-radius:8px; color:#991b1b; font-size:13px; font-weight:600; box-shadow:0 1px 2px rgba(0,0,0,0.05); animation:fadeIn 0.2s ease;">
                    <div style="display:flex; align-items:flex-start; gap:8px;">
                        <i class="fa-solid fa-triangle-exclamation" style="font-size:16px; margin-top:1px; color:#dc2626;"></i>
                        <div>
                            <span style="font-weight:700; color:#dc2626;">Invalid email ID:</span>
                            <span id="emailErrorMsg" style="color:#7f1d1d; font-weight:500;">Email must be a valid @gmail.com address.</span>
                        </div>
                    </div>
                </div>

                <!-- Real-time Email Valid Badge -->
                <div id="emailSuccessBox" style="display:none; margin-top:8px; padding:8px 14px; background:#f0fdf4; border:1px solid #86efac; border-radius:8px; color:#166534; font-size:12.5px; font-weight:600;">
                    <div style="display:flex; align-items:center; gap:8px;">
                        <i class="fa-solid fa-circle-check" style="color:#16a34a; font-size:15px;"></i>
                        <span>Valid @gmail.com email ID</span>
                    </div>
                </div>

                <div class="text-muted" id="emailGuidance" style="font-size:11.5px; margin-top:5px; color:#64748b;">
                    <i class="fa-solid fa-circle-info" style="font-size:11px;"></i> Email must be in <strong>@gmail.com</strong>. Enter your name+number (e.g. <code>pavan61</code>) and <strong>@gmail.com</strong> will be added automatically, or enter the full address.
                </div>
            </div>

            <div class="form-group"><label>Roll Number</label><input class="form-control" id="rollInput" value="<?= e($student['roll_number']) ?>" disabled></div>
            
            <div class="form-group" style="position:relative;">
                <label for="phoneInput" style="display:flex; justify-content:space-between; align-items:center; font-weight:600;">
                    <span>Mobile Number <span style="font-size:12px; font-weight:normal; color:var(--text-muted);">(10 digits only)</span> <span style="color:#dc2626;">*</span></span>
                    <span id="phoneCounter" style="font-size:12px; font-weight:600; color:var(--text-muted);">10 / 10 digits</span>
                </label>
                <div style="position:relative;">
                    <input class="form-control" type="text" name="phone" id="phoneInput"
                           value="<?= e($student['phone']) ?>"
                           placeholder="Enter 10-digit mobile number (e.g. 8766087273)"
                           maxlength="20"
                           inputmode="numeric"
                           autocomplete="tel"
                           style="padding-right: 42px; font-size:15px; letter-spacing:0.5px; font-weight:500;"
                           required>
                    <span id="phoneStatusIcon" style="position:absolute; right:12px; top:50%; transform:translateY(-50%); font-size:16px; pointer-events:none;"></span>
                </div>

                <!-- Real-time Error Alert Box -->
                <div id="phoneErrorBox" style="display:none; margin-top:8px; padding:10px 14px; background:#fef2f2; border:1px solid #f87171; border-radius:8px; color:#991b1b; font-size:13px; font-weight:600; box-shadow:0 1px 2px rgba(0,0,0,0.05); animation:fadeIn 0.2s ease;">
                    <div style="display:flex; align-items:flex-start; gap:8px;">
                        <i class="fa-solid fa-triangle-exclamation" style="font-size:16px; margin-top:1px; color:#dc2626;"></i>
                        <div>
                            <span style="font-weight:700; color:#dc2626;">Invalid number:</span>
                            <span id="phoneErrorMsg" style="color:#7f1d1d; font-weight:500;">Only 10 integers are accepted.</span>
                        </div>
                    </div>
                </div>

                <!-- Real-time Valid Badge -->
                <div id="phoneSuccessBox" style="display:none; margin-top:8px; padding:8px 14px; background:#f0fdf4; border:1px solid #86efac; border-radius:8px; color:#166534; font-size:12.5px; font-weight:600;">
                    <div style="display:flex; align-items:center; gap:8px;">
                        <i class="fa-solid fa-circle-check" style="color:#16a34a; font-size:15px;"></i>
                        <span>Valid 10-digit mobile number</span>
                    </div>
                </div>

                <div class="text-muted" id="phoneGuidance" style="font-size:11.5px; margin-top:5px; color:#64748b;">
                    <i class="fa-solid fa-circle-info" style="font-size:11px;"></i> Must contain exactly 10 integers (0-9). Characters, letters, or more than 10 numbers will show an invalid number error.
                </div>
            </div>

            <div class="form-group"><label>Address</label><input class="form-control" name="address" value="<?= e($student['address']) ?>"></div>
            <button class="btn btn-primary" type="submit" id="saveProfileBtn"><i class="fa-solid fa-floppy-disk"></i> Save Changes</button>
        </form>
    </div>

    <div class="card">
        <h3>Change Password</h3>
        <form method="POST" action="profile.php">
            <?= csrfField() ?>
            <input type="hidden" name="form_action" value="change_password">
            <div class="form-group"><label>Current Password</label><input class="form-control" type="password" name="current_password" required></div>
            <div class="form-group"><label>New Password</label><input class="form-control" type="password" name="new_password" minlength="8" required></div>
            <div class="form-group"><label>Confirm New Password</label><input class="form-control" type="password" name="confirm_password" minlength="8" required></div>
            <button class="btn btn-primary" type="submit"><i class="fa-solid fa-key"></i> Change Password</button>
        </form>
    </div>
</div>

<style>
@keyframes fieldShake {
    0%, 100% { transform: translateX(0); }
    20%, 60% { transform: translateX(-6px); }
    40%, 80% { transform: translateX(6px); }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {
    // Mobile Number Elements
    const phoneInput = document.getElementById('phoneInput');
    const phoneErrorBox = document.getElementById('phoneErrorBox');
    const phoneErrorMsg = document.getElementById('phoneErrorMsg');
    const phoneSuccessBox = document.getElementById('phoneSuccessBox');
    const phoneStatusIcon = document.getElementById('phoneStatusIcon');
    const phoneCounter = document.getElementById('phoneCounter');

    // Email ID Elements
    const emailInput = document.getElementById('emailInput');
    const emailErrorBox = document.getElementById('emailErrorBox');
    const emailErrorMsg = document.getElementById('emailErrorMsg');
    const emailSuccessBox = document.getElementById('emailSuccessBox');
    const emailStatusIcon = document.getElementById('emailStatusIcon');
    const emailSuggestionRow = document.getElementById('emailSuggestionRow');
    const suggestedEmailText = document.getElementById('suggestedEmailText');
    const applyEmailSuggestion = document.getElementById('applyEmailSuggestion');
    const btnAutoGenerateEmail = document.getElementById('btnAutoGenerateEmail');
    const nameInput = document.getElementById('nameInput');
    const rollInput = document.getElementById('rollInput');

    const profileForm = document.getElementById('profileForm');

    // ================= PHONE VALIDATION =================
    function checkPhone() {
        if (!phoneInput) return true;
        const val = phoneInput.value;
        const trimmed = val.trim();

        if (phoneCounter) {
            phoneCounter.textContent = trimmed.length + ' / 10 digits';
            if (trimmed.length === 10 && /^[0-9]{10}$/.test(trimmed)) {
                phoneCounter.style.color = '#16a34a';
            } else if (trimmed.length > 10) {
                phoneCounter.style.color = '#dc2626';
            } else {
                phoneCounter.style.color = 'var(--text-muted)';
            }
        }

        if (trimmed === '') {
            showPhoneError('Mobile number is required and must contain exactly 10 integers.');
            return false;
        }

        if (/[^0-9]/.test(trimmed)) {
            showPhoneError('Characters, letters, or symbols are not allowed. Only numbers (0-9) are accepted.');
            return false;
        }

        if (trimmed.length > 10) {
            showPhoneError('More than 10 numbers entered (' + trimmed.length + ' digits). Mobile number must be exactly 10 integers.');
            return false;
        }

        if (trimmed.length < 10) {
            showPhoneError('Must be exactly 10 integers (currently ' + trimmed.length + ' digits entered).');
            return false;
        }

        showPhoneSuccess();
        return true;
    }

    function showPhoneError(msg) {
        if (phoneErrorBox && phoneErrorMsg) {
            phoneErrorMsg.textContent = msg;
            phoneErrorBox.style.display = 'block';
        }
        if (phoneSuccessBox) phoneSuccessBox.style.display = 'none';
        if (phoneInput) {
            phoneInput.style.borderColor = '#dc2626';
            phoneInput.style.backgroundColor = '#fffbfb';
            phoneInput.style.boxShadow = '0 0 0 3px rgba(220, 38, 38, 0.15)';
        }
        if (phoneStatusIcon) {
            phoneStatusIcon.innerHTML = '<i class="fa-solid fa-circle-xmark" style="color:#dc2626;"></i>';
        }
    }

    function showPhoneSuccess() {
        if (phoneErrorBox) phoneErrorBox.style.display = 'none';
        if (phoneSuccessBox) phoneSuccessBox.style.display = 'block';
        if (phoneInput) {
            phoneInput.style.borderColor = '#16a34a';
            phoneInput.style.backgroundColor = '#f0fdf4';
            phoneInput.style.boxShadow = '0 0 0 3px rgba(22, 163, 74, 0.12)';
        }
        if (phoneStatusIcon) {
            phoneStatusIcon.innerHTML = '<i class="fa-solid fa-circle-check" style="color:#16a34a;"></i>';
        }
    }

    // ================= EMAIL VALIDATION & AUTO-COMPLETE =================
    function checkEmail(triggerAutoAppend = false) {
        if (!emailInput) return true;
        let val = emailInput.value.trim();

        // Auto-append @gmail.com if user entered name+number without '@' on blur/submit
        if (triggerAutoAppend && val !== '' && !val.includes('@')) {
            val = val.replace(/\s+/g, '').toLowerCase() + '@gmail.com';
            emailInput.value = val;
        }

        // Live Auto-Suggestion
        if (val !== '' && !val.includes('@')) {
            const cleanPrefix = val.replace(/\s+/g, '').toLowerCase();
            if (cleanPrefix.length > 0) {
                suggestedEmailText.textContent = cleanPrefix + '@gmail.com';
                emailSuggestionRow.style.display = 'flex';
            } else {
                emailSuggestionRow.style.display = 'none';
            }
        } else if (val.endsWith('@') || val.endsWith('@g') || val.endsWith('@gm') || val.endsWith('@gma') || val.endsWith('@gmai')) {
            const prefix = val.split('@')[0].trim().toLowerCase();
            if (prefix.length > 0) {
                suggestedEmailText.textContent = prefix + '@gmail.com';
                emailSuggestionRow.style.display = 'flex';
            } else {
                emailSuggestionRow.style.display = 'none';
            }
        } else {
            emailSuggestionRow.style.display = 'none';
        }

        if (val === '') {
            showEmailError('Email ID is required.');
            return false;
        }

        if (/\s/.test(val)) {
            showEmailError('Email ID cannot contain spaces.');
            return false;
        }

        if (!val.includes('@')) {
            showEmailError('Must be in @gmail.com (e.g. ' + val.toLowerCase() + '@gmail.com). Enter @gmail.com or click suggestion.');
            return false;
        }

        const parts = val.split('@');
        if (parts.length !== 2 || parts[0] === '') {
            showEmailError('Invalid format. Please enter a valid name+number before @gmail.com.');
            return false;
        }

        const username = parts[0];
        const domain = parts[1].toLowerCase();

        if (domain !== 'gmail.com') {
            showEmailError('Only @gmail.com email IDs are allowed (entered @' + domain + '). Must be @gmail.com.');
            return false;
        }

        if (!/^[a-zA-Z0-9._%+-]+$/.test(username)) {
            showEmailError('Invalid characters in email username. Only letters, numbers, and dots are allowed.');
            return false;
        }

        showEmailSuccess();
        return true;
    }

    function showEmailError(msg) {
        if (emailErrorBox && emailErrorMsg) {
            emailErrorMsg.textContent = msg;
            emailErrorBox.style.display = 'block';
        }
        if (emailSuccessBox) emailSuccessBox.style.display = 'none';
        if (emailInput) {
            emailInput.style.borderColor = '#dc2626';
            emailInput.style.backgroundColor = '#fffbfb';
            emailInput.style.boxShadow = '0 0 0 3px rgba(220, 38, 38, 0.15)';
        }
        if (emailStatusIcon) {
            emailStatusIcon.innerHTML = '<i class="fa-solid fa-circle-xmark" style="color:#dc2626;"></i>';
        }
    }

    function showEmailSuccess() {
        if (emailErrorBox) emailErrorBox.style.display = 'none';
        if (emailSuccessBox) emailSuccessBox.style.display = 'block';
        if (emailInput) {
            emailInput.style.borderColor = '#16a34a';
            emailInput.style.backgroundColor = '#f0fdf4';
            emailInput.style.boxShadow = '0 0 0 3px rgba(22, 163, 74, 0.12)';
        }
        if (emailStatusIcon) {
            emailStatusIcon.innerHTML = '<i class="fa-solid fa-circle-check" style="color:#16a34a;"></i>';
        }
    }

    // Auto-generate name+number button click
    if (btnAutoGenerateEmail) {
        btnAutoGenerateEmail.addEventListener('click', function () {
            const rawName = (nameInput ? nameInput.value : '').trim().toLowerCase();
            const firstName = rawName.split(' ')[0].replace(/[^a-z0-9]/g, '') || 'student';
            const rawRoll = (rollInput ? rollInput.value : '').trim();
            const rollDigits = rawRoll.replace(/\D/g, '') || '61';

            emailInput.value = firstName + rollDigits + '@gmail.com';
            checkEmail(false);
            emailInput.focus();
        });
    }

    // Click suggestion to apply
    if (applyEmailSuggestion) {
        applyEmailSuggestion.addEventListener('click', function () {
            if (suggestedEmailText.textContent) {
                emailInput.value = suggestedEmailText.textContent;
                checkEmail(false);
                emailInput.focus();
            }
        });
    }

    // Event listeners for Phone
    if (phoneInput) {
        phoneInput.addEventListener('input', checkPhone);
        phoneInput.addEventListener('keyup', checkPhone);
        phoneInput.addEventListener('change', checkPhone);
        phoneInput.addEventListener('paste', function () { setTimeout(checkPhone, 10); });
        phoneInput.addEventListener('blur', checkPhone);
        checkPhone();
    }

    // Event listeners for Email
    if (emailInput) {
        emailInput.addEventListener('input', function () { checkEmail(false); });
        emailInput.addEventListener('keyup', function () { checkEmail(false); });
        emailInput.addEventListener('paste', function () { setTimeout(function () { checkEmail(false); }, 10); });
        emailInput.addEventListener('blur', function () { checkEmail(true); });
        checkEmail(false);
    }

    // Form Submit Handler
    if (profileForm) {
        profileForm.addEventListener('submit', function (e) {
            const isEmailValid = checkEmail(true);
            const isPhoneValid = checkPhone();

            if (!isEmailValid) {
                e.preventDefault();
                emailInput.focus();
                emailInput.style.animation = 'none';
                emailInput.offsetHeight;
                emailInput.style.animation = 'fieldShake 0.4s ease';
                return;
            }

            if (!isPhoneValid) {
                e.preventDefault();
                phoneInput.focus();
                phoneInput.style.animation = 'none';
                phoneInput.offsetHeight;
                phoneInput.style.animation = 'fieldShake 0.4s ease';
                return;
            }
        });
    }
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
