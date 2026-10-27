
<?php
session_start();

if (
    !isset($_SESSION["admin_id"]) ||
    !isset($_SESSION["barangay_id"])
) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . "/../config/database.php";

$adminID = (string) $_SESSION["admin_id"];
$barangayID = (string) $_SESSION["barangay_id"];

if (empty($_SESSION["settings_csrf"])) {
    $_SESSION["settings_csrf"] = bin2hex(random_bytes(32));
}

$message = "";
$messageType = "";

/**
 * Escape output for HTML.
 */
function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
}

/**
 * Load the logged-in administrator for the assigned barangay.
 */
function loadAdmin(
    mysqli $conn,
    string $adminID,
    string $barangayID
): ?array {
    $sql = "
        SELECT
            u.userID,
            u.firstName,
            u.lastName,
            u.email,
            u.contactNo,
            u.profilePic,
            u.dateJoined,
            b.barangayName
        FROM users AS u
        LEFT JOIN barangay AS b
            ON b.barangayID = u.barangayID
        WHERE u.userID = ?
          AND u.barangayID = ?
          AND u.role = 'admin'
        LIMIT 1
    ";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $adminID, $barangayID);
    $stmt->execute();

    $result = $stmt->get_result();
    $admin = $result->fetch_assoc() ?: null;

    $stmt->close();

    return $admin;
}

/**
 * Generate profile initials without relying on global variables.
 */
function getInitials(string $firstName, string $lastName): string
{
    $firstName = trim($firstName);
    $lastName = trim($lastName);

    $firstInitial = $firstName !== ""
        ? (function_exists("mb_substr")
            ? mb_substr($firstName, 0, 1, "UTF-8")
            : substr($firstName, 0, 1))
        : "";

    $lastInitial = $lastName !== ""
        ? (function_exists("mb_substr")
            ? mb_substr($lastName, 0, 1, "UTF-8")
            : substr($lastName, 0, 1))
        : "";

    $initials = $firstInitial . $lastInitial;

    if ($initials === "") {
        return "AD";
    }

    return function_exists("mb_strtoupper")
        ? mb_strtoupper($initials, "UTF-8")
        : strtoupper($initials);
}

/**
 * Process submitted forms.
 */
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $csrfToken = $_POST["csrf_token"] ?? "";

    if (
        !is_string($csrfToken) ||
        !hash_equals($_SESSION["settings_csrf"], $csrfToken)
    ) {
        $message = "Your session could not be verified. Please refresh and try again.";
        $messageType = "error";
    } else {
        $action = $_POST["action"] ?? "";

        /*
         * UPDATE PROFILE PICTURE
         */
        if ($action === "change_picture") {
            $admin = loadAdmin($conn, $adminID, $barangayID);

            if (!$admin) {
                http_response_code(403);
                exit("Unauthorized.");
            }

            if (!isset($_FILES["profile_picture"])) {
                $message = "Please select a picture to upload.";
                $messageType = "error";
            } elseif (
                $_FILES["profile_picture"]["error"] !== UPLOAD_ERR_OK
            ) {
                $message = "The image could not be uploaded. Please select it again.";
                $messageType = "error";
            } elseif (
                $_FILES["profile_picture"]["size"] > 3 * 1024 * 1024
            ) {
                $message = "The image must be 3 MB or smaller.";
                $messageType = "error";
            } else {
                $temporaryFile = $_FILES["profile_picture"]["tmp_name"];
                $imageInfo = @getimagesize($temporaryFile);

                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mimeType = $finfo->file($temporaryFile);

                $allowedTypes = [
                    "image/jpeg" => "jpg",
                    "image/png"  => "png",
                    "image/webp" => "webp"
                ];

                if (
                    $imageInfo === false ||
                    !isset($allowedTypes[$mimeType])
                ) {
                    $message = "Use a valid JPG, PNG, or WEBP image.";
                    $messageType = "error";
                } else {
                    $uploadDirectory =
                        __DIR__ . "/uploads/profile_pictures/";

                    if (
                        !is_dir($uploadDirectory) &&
                        !mkdir($uploadDirectory, 0755, true) &&
                        !is_dir($uploadDirectory)
                    ) {
                        $message = "The upload folder could not be created.";
                        $messageType = "error";
                    } else {
                        $filename =
                            bin2hex(random_bytes(16)) .
                            "." .
                            $allowedTypes[$mimeType];

                        $destination = $uploadDirectory . $filename;

                        $databasePath =
                            "uploads/profile_pictures/" . $filename;

                        if (
                            !move_uploaded_file(
                                $temporaryFile,
                                $destination
                            )
                        ) {
                            $message = "The picture could not be saved.";
                            $messageType = "error";
                        } else {
                            $stmt = $conn->prepare("
                                UPDATE users
                                SET profilePic = ?
                                WHERE userID = ?
                                  AND barangayID = ?
                                  AND role = 'admin'
                            ");

                            $stmt->bind_param(
                                "sss",
                                $databasePath,
                                $adminID,
                                $barangayID
                            );

                            $updated = $stmt->execute()
                                && $stmt->affected_rows === 1;

                            $stmt->close();

                            if ($updated) {
                                $oldPicture =
                                    (string) ($admin["profilePic"] ?? "");

                                /*
                                 * Delete only an old file located inside
                                 * this page's profile-picture directory.
                                 */
                                if (
                                    str_starts_with(
                                        $oldPicture,
                                        "uploads/profile_pictures/"
                                    )
                                ) {
                                    $oldFile = __DIR__ . "/" . $oldPicture;

                                    $realUploadDirectory =
                                        realpath($uploadDirectory);

                                    $realOldFile = realpath($oldFile);

                                    if (
                                        $realUploadDirectory !== false &&
                                        $realOldFile !== false &&
                                        str_starts_with(
                                            $realOldFile,
                                            $realUploadDirectory .
                                            DIRECTORY_SEPARATOR
                                        ) &&
                                        is_file($realOldFile)
                                    ) {
                                        @unlink($realOldFile);
                                    }
                                }

                                $message =
                                    "Profile picture updated successfully.";
                                $messageType = "success";
                            } else {
                                @unlink($destination);

                                $message =
                                    "Could not update the profile picture in the database.";
                                $messageType = "error";
                            }
                        }
                    }
                }
            }
        }

        /*
         * CHANGE PASSWORD
         */
        elseif ($action === "change_password") {
            $currentPassword =
                $_POST["current_password"] ?? "";

            $newPassword =
                $_POST["new_password"] ?? "";

            $confirmPassword =
                $_POST["confirm_password"] ?? "";

            if (
                !is_string($currentPassword) ||
                !is_string($newPassword) ||
                !is_string($confirmPassword)
            ) {
                $message = "Invalid password input.";
                $messageType = "error";
            } elseif (
                $currentPassword === "" ||
                $newPassword === "" ||
                $confirmPassword === ""
            ) {
                $message = "Please complete all password fields.";
                $messageType = "error";
            } elseif ($newPassword !== $confirmPassword) {
                $message = "The new passwords do not match.";
                $messageType = "error";
            } elseif (strlen($newPassword) < 8) {
                $message =
                    "Your new password must be at least 8 characters long.";
                $messageType = "error";
            } elseif (strlen($newPassword) > 72) {
                $message =
                    "Your new password must not exceed 72 bytes.";
                $messageType = "error";
            } else {
                $stmt = $conn->prepare("
                    SELECT pass
                    FROM users
                    WHERE userID = ?
                      AND barangayID = ?
                      AND role = 'admin'
                    LIMIT 1
                ");

                $stmt->bind_param("ss", $adminID, $barangayID);
                $stmt->execute();

                $result = $stmt->get_result();
                $account = $result->fetch_assoc();

                $stmt->close();

                if (
                    !$account ||
                    !password_verify(
                        $currentPassword,
                        $account["pass"]
                    )
                ) {
                    $message = "Your current password is incorrect.";
                    $messageType = "error";
                } elseif (
                    password_verify(
                        $newPassword,
                        $account["pass"]
                    )
                ) {
                    $message =
                        "Choose a password different from your current password.";
                    $messageType = "error";
                } else {
                    $newHash = password_hash(
                        $newPassword,
                        PASSWORD_DEFAULT
                    );

                    $stmt = $conn->prepare("
                        UPDATE users
                        SET pass = ?
                        WHERE userID = ?
                          AND barangayID = ?
                          AND role = 'admin'
                    ");

                    $stmt->bind_param(
                        "sss",
                        $newHash,
                        $adminID,
                        $barangayID
                    );

                    $updated = $stmt->execute()
                        && $stmt->affected_rows === 1;

                    $stmt->close();

                    if ($updated) {
                        $message = "Password changed successfully.";
                        $messageType = "success";
                    } else {
                        $message =
                            "Could not change your password. Please try again.";
                        $messageType = "error";
                    }
                }
            }
        } else {
            $message = "Invalid settings action.";
            $messageType = "error";
        }
    }
}

/*
 * Reload current admin information after form processing.
 */
$admin = loadAdmin($conn, $adminID, $barangayID);

if (!$admin) {
    http_response_code(403);
    exit("The admin account could not be found.");
}

$fullName = trim(
    (string) $admin["firstName"] .
    " " .
    (string) $admin["lastName"]
);

$initials = getInitials(
    (string) $admin["firstName"],
    (string) $admin["lastName"]
);

$profilePic = trim((string) ($admin["profilePic"] ?? ""));

$profilePicUrl = $profilePic !== ""
    ? $profilePic
    : "";

$dateJoined = !empty($admin["dateJoined"])
    ? date("F j, Y", strtotime($admin["dateJoined"]))
    : "Not available";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Settings | CitiSense Admin</title>

    <link
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
    >

    <link rel="stylesheet" href="../css/dashboard.css">

    <link rel="stylesheet" href="../css/settings.css">
</head>

<body>

    <!-- Existing CitiSense dashboard sidebar -->
    <?php include __DIR__ . "/partial/sidebar.php"; ?>

    <!-- Main dashboard content -->
    <main class="main-content">
        <div class="settings-page">

            <header class="settings-header">
                <h1>Account Settings</h1>
            </header>

            <?php if ($message !== ""): ?>
                <div
                    class="settings-alert settings-alert-<?= e($messageType) ?>"
                    role="status"
                    aria-live="polite"
                >
                    <i class="bi <?= $messageType === "success"
                        ? "bi-check-circle-fill"
                        : "bi-exclamation-circle-fill" ?>"></i>

                    <span><?= e($message) ?></span>
                </div>
            <?php endif; ?>

            <!-- PROFILE PICTURE -->
            <section class="settings-card">

                <div class="settings-profile">
                    <div class="settings-avatar-wrapper">
                        <button
                            type="button"
                            class="settings-avatar"
                            id="avatarPickerButton"
                            aria-label="Choose a profile picture"
                            title="Change profile picture"
                        >
                            <?php if ($profilePicUrl !== ""): ?>
                                <img
                                    src="<?= e($profilePicUrl) ?>"
                                    alt="Administrator profile picture"
                                    id="profilePreview"
                                >
                            <?php else: ?>
                                <span id="profileInitials">
                                    <?= e($initials) ?>
                                </span>
                            <?php endif; ?>
                        </button>
                    </div>

                    <div class="settings-profile-details">
                        <h3><?= e($fullName) ?></h3>
                        <p>System Administrator</p>

                        <form
                            id="pictureForm"
                            action="settings.php"
                            method="POST"
                            enctype="multipart/form-data"
                        >
                            <input
                                type="hidden"
                                name="csrf_token"
                                value="<?= e($_SESSION["settings_csrf"]) ?>"
                            >

                            <input
                                type="hidden"
                                name="action"
                                value="change_picture"
                            >

                            <input
                                type="file"
                                id="profilePictureInput"
                                name="profile_picture"
                                accept="image/jpeg,image/png,image/webp"
                                hidden
                            >

                            <button
                                class="settings-btn settings-btn-primary"
                                type="button"
                                id="choosePictureButton"
                            >
                                <i class="bi bi-camera"></i>
                                Choose Picture
                            </button>
                        </form>
                    </div>
                </div>
            </section>

            <!-- ADMIN INFORMATION -->
            <section class="settings-card">
                <div class="settings-card-header">
                    <h2>Admin Information</h2>
                </div>

                <div class="settings-info-grid">
                    <div class="settings-field">
                        <label for="fullName">Full Name</label>
                        <input
                            id="fullName"
                            type="text"
                            value="<?= e($fullName) ?>"
                            readonly
                        >
                    </div>

                    <div class="settings-field">
                        <label for="adminEmail">Email Address</label>
                        <input
                            id="adminEmail"
                            type="email"
                            value="<?= e($admin["email"]) ?>"
                            readonly
                        >
                    </div>

                    <div class="settings-field">
                        <label for="contactNo">Contact Number</label>
                        <input
                            id="contactNo"
                            type="text"
                            value="<?= e($admin["contactNo"]) ?>"
                            readonly
                        >
                    </div>

                    <div class="settings-field">
                        <label for="barangay">Assigned Barangay</label>
                        <input
                            id="barangay"
                            type="text"
                            value="<?= e($admin["barangayName"] ?? "Not assigned") ?>"
                            readonly
                        >
                    </div>

                    <div class="settings-field">
                        <label for="adminID">Admin ID</label>
                        <input
                            id="adminID"
                            type="text"
                            value="<?= e($admin["userID"]) ?>"
                            readonly
                        >
                    </div>

                    <div class="settings-field">
                        <label for="dateJoined">Date Joined</label>
                        <input
                            id="dateJoined"
                            type="text"
                            value="<?= e($dateJoined) ?>"
                            readonly
                        >
                    </div>
                </div>
            </section>

            <!-- CHANGE PASSWORD -->
            <section class="settings-card">
                <div class="settings-card-header">
                    <h2>Change Password</h2>
                </div>

                <form
                    id="passwordForm"
                    action="settings.php"
                    method="POST"
                    autocomplete="off"
                >
                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= e($_SESSION["settings_csrf"]) ?>"
                    >

                    <input
                        type="hidden"
                        name="action"
                        value="change_password"
                    >

                    <div class="settings-password-grid">
                        <div class="settings-field">
                            <label for="currentPassword">
                                Current Password
                            </label>

                            <input
                                id="currentPassword"
                                type="password"
                                name="current_password"
                                autocomplete="current-password"
                                required
                            >
                        </div>

                        <div class="settings-field">
                            <label for="newPassword">
                                New Password
                            </label>

                            <input
                                id="newPassword"
                                type="password"
                                name="new_password"
                                minlength="8"
                                maxlength="72"
                                autocomplete="new-password"
                                required
                            >

                            <small>
                                Use at least 8 characters.
                            </small>
                        </div>

                        <div class="settings-field">
                            <label for="confirmPassword">
                                Confirm New Password
                            </label>

                            <input
                                id="confirmPassword"
                                type="password"
                                name="confirm_password"
                                minlength="8"
                                maxlength="72"
                                autocomplete="new-password"
                                required
                            >
                        </div>
                    </div>

                    <div class="settings-actions">
                        <button
                            class="settings-btn settings-btn-primary"
                            type="submit"
                        >
                            <i class="bi bi-shield-lock"></i>
                            Update Password
                        </button>
                    </div>
                </form>
            </section>

        </div>
    </main>

    <script>
    (() => {
        const pictureInput = document.getElementById(
            "profilePictureInput"
        );

        const choosePictureButton = document.getElementById(
            "choosePictureButton"
        );

        const avatarPickerButton = document.getElementById(
            "avatarPickerButton"
        );

        const pictureForm = document.getElementById("pictureForm");

        const passwordForm = document.getElementById("passwordForm");

        const newPasswordInput = document.getElementById("newPassword");

        const confirmPasswordInput = document.getElementById(
            "confirmPassword"
        );

        let previewUrl = null;

        function openPicturePicker() {
            pictureInput.click();
        }

        choosePictureButton.addEventListener(
            "click",
            openPicturePicker
        );

        avatarPickerButton.addEventListener(
            "click",
            openPicturePicker
        );

        pictureInput.addEventListener("change", () => {
            const file = pictureInput.files[0];

            if (!file) {
                return;
            }

            const allowedTypes = [
                "image/jpeg",
                "image/png",
                "image/webp"
            ];

            if (!allowedTypes.includes(file.type)) {
                alert("Please select a JPG, PNG, or WEBP image.");
                pictureInput.value = "";
                return;
            }

            if (file.size > 3 * 1024 * 1024) {
                alert("The image must be 3 MB or smaller.");
                pictureInput.value = "";
                return;
            }

            if (previewUrl) {
                URL.revokeObjectURL(previewUrl);
            }

            previewUrl = URL.createObjectURL(file);

            let preview = document.getElementById("profilePreview");

            if (!preview) {
                avatarPickerButton.innerHTML = "";

                preview = document.createElement("img");
                preview.id = "profilePreview";
                preview.alt = "Selected profile picture preview";

                avatarPickerButton.appendChild(preview);
            }

            preview.src = previewUrl;

            if (confirm("Use this picture as your profile photo?")) {
                choosePictureButton.disabled = true;
                choosePictureButton.textContent = "Uploading...";

                pictureForm.requestSubmit();
            } else {
                pictureInput.value = "";

                if (previewUrl) {
                    URL.revokeObjectURL(previewUrl);
                    previewUrl = null;
                }

                window.location.reload();
            }
        });

        passwordForm.addEventListener("submit", (event) => {
            if (
                newPasswordInput.value !==
                confirmPasswordInput.value
            ) {
                event.preventDefault();

                alert("The new passwords do not match.");
                confirmPasswordInput.focus();
            }
        });
    })();
    </script>

</body>
</html>
