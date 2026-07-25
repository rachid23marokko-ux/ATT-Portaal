<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/Helpers/bootstrap.php';

if (!empty($_SESSION['user'])) {
    redirect('/dashboard.php');
}

$error = null;
$success = $_SESSION['success'] ?? null;
unset($_SESSION['success']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        http_response_code(419);
        exit('Ongeldige beveiligingstoken.');
    }

    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');

    $stmt = db()->prepare(
        'SELECT
            u.id,
            u.name,
            u.email,
            u.password_hash,
            u.is_active,
            u.session_version,
            r.name AS role
         FROM users u
         JOIN roles r ON r.id = u.role_id
         WHERE u.email = :email
         LIMIT 1'
    );

    $stmt->execute([
        'email' => $email,
    ]);

    $user = $stmt->fetch();

    if (
        $user
        && (bool) $user['is_active']
        && password_verify($password, $user['password_hash'])
    ) {
        session_regenerate_id(true);
$newSessionVersion = ((int) $user['session_version']) + 1;

$update = \db()->prepare("
    UPDATE users
    SET
        session_version = :version,
        is_online = 1,
        last_seen_at = NOW()
    WHERE id = :id
");

$update->execute([
    'version' => $newSessionVersion,
    'id' => $user['id'],
]);

$user['session_version'] = $newSessionVersion;

        $_SESSION['user'] = [
            'id' => (int) $user['id'],
            'name' => $user['name'],
            'email' => $user['email'],
            'role' => $user['role'],
            'session_version' => (int) $user['session_version'],
        ];

        db()->prepare(
            'UPDATE users
             SET is_online = 1,
                 last_seen_at = NOW()
             WHERE id = :id'
        )->execute([
            'id' => $user['id'],
        ]);

        redirect('/dashboard.php');
    }

    $error = 'E-mailadres of wachtwoord is onjuist.';
}
?>
<!doctype html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >

    <title>Inloggen | ATT Portaal</title>

    <style>
        * {
            box-sizing: border-box;
        }

        html,
        body {
            min-height: 100%;
        }

       body {
    margin: 0;
    min-height: 100vh;
    overflow-x: hidden;
    color: #172033;
    background:
        radial-gradient(
            circle at 15% 20%,
            rgba(255, 255, 255, 0.16),
            transparent 30%
        ),
        linear-gradient(
            135deg,
            #123b67 0%,
            #1769aa 55%,
            #2f80c9 100%
        );
    font-family: Arial, Helvetica, sans-serif;
}

        .login-page {
            width: 100%;
            min-height: 100vh;
            display: grid;
            grid-template-columns:
                minmax(300px, 1fr)
                minmax(420px, 530px);
            align-items: center;
            gap: 80px;
            padding: 50px 7vw;
        }

        .login-intro {
            max-width: 650px;
            color: #ffffff;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 14px;
            margin-bottom: 30px;
        }

        .brand-icon {
            width: 58px;
            height: 58px;
            display: grid;
            place-items: center;
            border: 1px solid rgba(255, 255, 255, 0.35);
            border-radius: 17px;
            background: rgba(255, 255, 255, 0.16);
            box-shadow: 0 14px 30px rgba(0, 0, 0, 0.12);
            font-size: 28px;
        }

        .brand-name {
            font-size: 25px;
            font-weight: 800;
        }

        .login-intro h1 {
            max-width: 650px;
            margin: 0 0 20px;
            font-size: clamp(40px, 4vw, 62px);
            line-height: 1.08;
            letter-spacing: -1.5px;
        }

        .login-intro p {
            max-width: 620px;
            margin: 0;
            color: rgba(255, 255, 255, 0.86);
            font-size: 19px;
            line-height: 1.65;
        }
.login-features {
    list-style: none;
    padding: 0;
    margin: 30px 0 0;
    max-width: 480px;
}

.login-features li {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 14px 0;
    color: #ffffff;
    font-size: 19px;
    font-weight: 600;
    border-bottom: 1px solid rgba(255, 255, 255, 0.20);
}

.login-features li:last-child {
    border-bottom: none;
}

.login-features li span:first-child {
    width: 32px;
    text-align: center;
    font-size: 23px;
}

        .login-card {
            width: 100%;
            max-width: 510px;
            justify-self: end;
            padding: 42px;
            border: 1px solid rgba(255, 255, 255,.65);
            border-radius: 24px;
            background: rgba(255, 255, 255,.98);
            box-shadow: 0 28px 65px rgba(13, 38, 69,.32);
        }

        .login-card-header {
            margin-bottom: 28px;
        }

        .login-card h2 {
            margin: 0 0 10px;
            font-size: 32px;
            line-height: 1.2;
        }

        .login-card-header p {
            margin: 0;
            color: #667085;
            line-height: 1.6;
        }

        .alert {
            margin-bottom: 22px;
            padding: 14px 16px;
            border-radius: 12px;
            font-size: 14px;
            line-height: 1.5;
        }

        .alert.error {
            border: 1px solid #fecdca;
            background: #fef3f2;
            color: #b42318;
        }

        .alert.success {
            border: 1px solid #abefc6;
            background: #ecfdf3;
            color: #067647;
        }

        .login-form {
            display: grid;
            gap: 20px;
        }

        .form-field {
            display: grid;
            gap: 8px;
        }

        .form-field label {
            color: #344054;
            font-size: 14px;
            font-weight: 700;
        }

        .form-field input {
            width: 100%;
            min-height: 52px;
            padding: 13px 15px;
            border: 1px solid #d5dbe5;
            border-radius: 11px;
            background: #ffffff;
            color: #172033;
            font-size: 16px;
            outline: none;
            transition:
                border-color 0.2s ease,
                box-shadow 0.2s ease;
        }

        .form-field input:focus {
            border-color: #1769aa;
            box-shadow: 0 0 0 4px rgba(23, 105, 170, 0.13);
        }

        .login-button {
            width: 100%;
            min-height: 52px;
            margin-top: 4px;
            border: 0;
            border-radius: 11px;
            background: linear-gradient(
                135deg,
                #1769aa,
                #2f80c9
            );
            color: #ffffff;
            font-size: 16px;
            font-weight: 800;
            cursor: pointer;
            box-shadow: 0 12px 24px rgba(23, 105, 170, 0.25);
        }

        .login-button:hover {
            filter: brightness(1.05);
        }

        .login-links {
            margin-top: 24px;
            text-align: center;
        }

        .login-links a {
            color: #1769aa;
            font-size: 14px;
            font-weight: 700;
            text-decoration: none;
        }

        .login-links a:hover {
            text-decoration: underline;
        }

        .security-text {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 7px;
            margin-top: 22px;
            color: #87909f;
            font-size: 12px;
        }

        @media (max-width: 980px) {
            .login-page {
                grid-template-columns: 1fr;
                justify-items: center;
                gap: 36px;
                padding: 40px 22px;
            }

            .login-intro {
                text-align: center;
            }

            .brand {
                justify-content: center;
            }

            .login-intro h1,
            .login-intro p {
                margin-right: auto;
                margin-left: auto;
            }

            .login-card {
                max-width: 520px;
                justify-self: center;
            }
        }

        @media (max-width: 600px) {
            .login-page {
                display: block;
                padding: 24px 16px;
            }

            .login-intro {
                margin-bottom: 28px;
            }

            .brand {
                margin-bottom: 18px;
            }

            .brand-icon {
                width: 48px;
                height: 48px;
                border-radius: 14px;
                font-size: 23px;
            }

            .brand-name {
                font-size: 21px;
            }

            .login-intro h1 {
                margin-bottom: 12px;
                font-size: 34px;
            }

            .login-intro p {
                font-size: 15px;
                line-height: 1.55;
            }

            .login-card {
                padding: 28px 22px;
                border-radius: 19px;
            }

            .login-card h2 {
                font-size: 27px;
            }
        }
    </style>
</head>

<body>
    <main class="login-page">
        <section class="login-intro">
            <div class="brand">
                <div class="brand-icon" aria-hidden="true">
                    📍
                </div>

                <div class="brand-name">
                    ATT Portaal
                </div>
            </div>

            <h1>Voor Brabo &amp; NLW chauffeurs</h1>

<p>Alles wat je onderweg nodig hebt, op één centrale plek.</p>

<ul class="login-features">
    <li><span>📍</span><span>Adressen</span></li>
    <li><span>🗺️</span><span>Navigatie</span></li>
    <li><span>📄</span><span>Documenten</span></li>
    <li><span>⚠️</span><span>Veiligheidsinformatie</span></li>
    <li><span>📢</span><span>Mededelingen</span></li>
</ul>
        </section>

        <section class="login-card">
            <div class="login-card-header">
                <h2>Welkom terug</h2>

                <p>
                    Log in met je account om verder te gaan.
                </p>
            </div>

            <?php if ($success): ?>
                <div class="alert success" role="alert">
                    <?= e($success) ?>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert error" role="alert">
                    <?= e($error) ?>
                </div>
            <?php endif; ?>

            <form method="post" class="login-form">
                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= e(csrf_token()) ?>"
                >

                <div class="form-field">
                    <label for="email">
                        E-mailadres
                    </label>

                    <input
                        id="email"
                        type="email"
                        name="email"
                        value="<?= e($_POST['email'] ?? '') ?>"
                        autocomplete="email"
                        required
                        autofocus
                    >
                </div>

                <div class="form-field">
                    <label for="password">
                        Wachtwoord
                    </label>

                    <input
                        id="password"
                        type="password"
                        name="password"
                        autocomplete="current-password"
                        required
                    >
                </div>

                <button
                    type="submit"
                    class="login-button"
                >
                    Inloggen
                </button>
            </form>

            <div class="login-links">
                <a href="/forgot-password.php">
                    Wachtwoord vergeten?
                </a>
            </div>

            <div class="security-text">
                <span aria-hidden="true">🔒</span>
                <span>Beveiligde toegang tot ATT Portaal</span>
            </div>
        </section>
    </main>
</body>
</html>
