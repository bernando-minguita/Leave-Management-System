<?php
/**
 * Sign in page.
 * Uses: $errors (array), $username, $redirect
 */
$errors   = $errors ?? [];
$username = $username ?? '';
$redirect = $redirect ?? '';
?>

<div class="card border-0 shadow auth-card">
    <div class="card-body p-4">
        <h1 class="h5 mb-1">Sign in</h1>
        <p class="text-body-secondary small mb-4">
            Use your administrator or operator account.
        </p>

        <?php if (!empty($error) && is_string($error)): ?>
            <div class="alert alert-danger py-2 small">
                <i class="bi bi-exclamation-octagon-fill me-1"></i><?= e($error) ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($errors) && is_array($errors)): ?>
            <div class="alert alert-danger py-2 small mb-3">
                <ul class="mb-0 ps-3">
                    <?php foreach ($errors as $message): ?>
                        <li><?= e((string)$message) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="post" action="<?= e(url('login')) ?>" id="loginForm" autocomplete="on" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="redirect" value="<?= e($redirect) ?>">

            <div class="mb-3">
                <label class="form-label" for="username">Username</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-person"></i></span>
                    <input type="text" class="form-control<?= isset($errors['username']) ? ' is-invalid' : '' ?>"
                           id="username" name="username" value="<?= e($username) ?>"
                           autocomplete="username" required autofocus>
                    <?php if (isset($errors['username'])): ?>
                        <div class="invalid-feedback"><?= e($errors['username']) ?></div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="mb-4">
                <label class="form-label" for="password">Password</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-lock"></i></span>
                    <input type="password"
                           class="form-control<?= isset($errors['password']) ? ' is-invalid' : '' ?>"
                           id="password" name="password" autocomplete="current-password" required>
                    <button class="btn btn-outline-secondary" type="button" id="togglePassword"
                            aria-label="Show password">
                        <i class="bi bi-eye"></i>
                    </button>
                    <?php if (isset($errors['password'])): ?>
                        <div class="invalid-feedback"><?= e($errors['password']) ?></div>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (!empty($recaptcha)): ?>
                <div class="mb-3">
                    <!-- reCAPTCHA is only rendered when the server has keys and
                         is not on a localhost/dev host; the secret key is never
                         sent to the browser, only the site key.

                         data-size="invisible" removes the checkbox: Google
                         renders a zero-height widget and only interrupts the
                         user with a challenge if it distrusts the traffic.

                         IMPORTANT: the site key must have been issued for the
                         "reCAPTCHA v2 - Invisible" type in the Google console.
                         A key minted for the visible checkbox silently fails
                         to render at data-size="invisible". -->
                    <div class="g-recaptcha"
                         data-sitekey="<?= e($siteKey ?? '') ?>"
                         data-size="invisible"
                         data-callback="lmsCaptchaSolved"
                         data-expired-callback="lmsCaptchaExpired"></div>
                    <script src="https://www.google.com/recaptcha/api.js" async defer></script>
                </div>

                <script>
                /* Invisible reCAPTCHA cannot submit the form on its own, so the
                   first submit is swallowed, grecaptcha.execute() fetches a
                   token, and the data-callback then submits for real.

                   `armed` is what makes this safe regardless of whether the
                   browser raises a submit event for form.submit() (that has
                   differed across engines): a submission carrying a fresh
                   token passes straight through and disarms, so there is no
                   path that could re-enter execute() in a loop.

                   api.js is loaded with async defer, so the handler polls for
                   it rather than checking once at parse time - a one-off check
                   would leave the form permanently dead on any host that
                   fetches api.js after this inline block. */
                (function () {
                    'use strict';

                    var form   = document.getElementById('loginForm');
                    var widget = document.querySelector('.g-recaptcha[data-size="invisible"]');

                    if (!form || !widget) { return; }

                    var armed   = false;   // a token is waiting to be submitted
                    var running = false;   // execute() is already in flight
                    var waited  = 0;

                    window.lmsCaptchaSolved = function () {
                        armed   = true;
                        running = false;
                        form.submit();
                    };

                    window.lmsCaptchaExpired = function () {
                        // Token went stale before they hit submit: take a fresh
                        // one on the next attempt rather than resending it.
                        armed   = false;
                        running = false;
                    };

                    function executeWhenReady() {
                        if (typeof window.grecaptcha !== 'undefined') {
                            grecaptcha.ready(function () { grecaptcha.execute(); });
                            return;
                        }

                        if (++waited > 50) {              // ~5s: api.js never came
                            running = false;
                            if (window.FL) {
                                window.FL.toast('danger',
                                    'Security check could not load. Refresh the page and try again.');
                            }
                        } else {
                            setTimeout(executeWhenReady, 100);
                        }
                    }

                    form.addEventListener('submit', function (e) {
                        if (armed) {
                            armed = false;   // consumed by this submission
                            return;
                        }

                        e.preventDefault();

                        if (running) { return; }   // a challenge is already in flight

                        running = true;
                        waited  = 0;
                        executeWhenReady();
                    });
                })();
                </script>
            <?php endif; ?>

            <button type="submit" class="btn btn-primary w-100">
                <i class="bi bi-box-arrow-in-right me-1"></i>Sign in
            </button>
        </form>

        <div class="small text-body-secondary mt-4 pt-3 border-top">
            <i class="bi bi-shield-check me-1"></i>
            Sessions are hardened and passwords are stored as bcrypt hashes.
        </div>
    </div>
</div>
