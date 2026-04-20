<header class="main-header">
        <!-- In Header -->
<div class="logo-container">
    <img src="/LingunanFitnessGym/assets/image/Logo.png" alt="Lingunan Fitness Gym" class="logo-img">
    <div class="logo-text">Lingunan<span>FitnessGym</span></div>
</div>

        <nav class="nav-menu" id="navMenu">
            <div class="close-btn" id="closeBtn">&times;</div>
            <ul>
                <li><a href="/LingunanFitnessGym/index.php">Home</a></li>
                <li><a href="#membership">Membership</a></li>
                <li><a href="#gallery">Gallery</a></li>
                <li><a href="#about">About Us</a></li>
            </ul>
        </nav>

        <div class="header-actions">
            <?php if (
                !(isset($_SERVER['SCRIPT_NAME']) && (
                    strpos($_SERVER['SCRIPT_NAME'], '/member/index.php') !== false ||
                    strpos($_SERVER['SCRIPT_NAME'], '/client/index.php') !== false
                ))
            ): ?>
                <a href="/LingunanFitnessGym/public/member/index.php" class="btn-cta">Login</a>
            <?php endif; ?>
            <div class="hamburger" id="hamburger">
                <div></div>
                <div></div>
                <div></div>
            </div>
        </div>
    </header>