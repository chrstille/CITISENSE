<?php
/* Left-hand brand panel shared by login, forgot and reset password pages */
?>
<aside class="auth-brand">

    <div class="auth-brand-top">
        <img src="../css/images/brand.png" alt="CitiSense logo" class="auth-logo">
        <span class="auth-brand-name">Citi<span>Sense</span></span>
    </div>

    <div class="auth-brand-copy">
        <span class="auth-eyebrow">Barangay Admin Portal</span>
        <h1>Keep your community safe, clean and heard.</h1>
        <p>Review resident reports, track progress and resolve issues, all in one place.</p>

        <ul class="auth-points">
            <li><i class="bi bi-lightning-charge-fill"></i> Get notified the moment a report comes in</li>
            <li><i class="bi bi-geo-alt-fill"></i> See problem areas on a live heatmap</li>
            <li><i class="bi bi-check2-circle"></i> Track every report until it's resolved</li>
        </ul>
    </div>

    <small class="auth-brand-foot">© <?= date("Y") ?> CitiSense. All rights reserved.</small>

</aside>
