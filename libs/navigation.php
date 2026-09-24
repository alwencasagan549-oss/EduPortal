<?php
function renderPortalNav(array $pages, string $currentPage, array $identity, string $navigationLabel): void
{
    $sidebarId = (string) ($identity['sidebar_id'] ?? 'portal-sidebar');
    $displayName = (string) ($identity['name'] ?? 'User');
    $statusLabel = (string) ($identity['status'] ?? 'Online');
    $avatarIcon = (string) ($identity['avatar_icon'] ?? 'fas fa-user');
    $statusIcon = (string) ($identity['status_icon'] ?? 'fas fa-circle');
    $logoutIcon = (string) ($identity['logout_icon'] ?? 'fas fa-right-from-bracket');
    ?>
    <script src="../assets/js/trusted_types.js"></script>
    <a class="skip-link" href="#main-content">Skip to main content</a>
    <aside class="sidebar" id="<?php echo htmlspecialchars($sidebarId, ENT_QUOTES, 'UTF-8'); ?>">
        <div class="sidebar-header">
            <div class="sidebar-logo" aria-hidden="true">
                <i class="<?php echo htmlspecialchars($avatarIcon, ENT_QUOTES, 'UTF-8'); ?>"></i>
            </div>
            <div class="sidebar-brand">Edu<span>Portal</span></div>
        </div>
        <nav class="sidebar-menu" aria-label="<?php echo htmlspecialchars($navigationLabel, ENT_QUOTES, 'UTF-8'); ?>">
            <ul>
                <?php foreach ($pages as $key => $page): ?>
                    <?php $isCurrent = $key === $currentPage; ?>
                    <li class="menu-item">
                        <a href="<?php echo htmlspecialchars((string) $page['href'], ENT_QUOTES, 'UTF-8'); ?>"
                           class="menu-link<?php echo $isCurrent ? ' active' : ''; ?>"
                           <?php echo $isCurrent ? 'aria-current="page"' : ''; ?>>
                            <i class="<?php echo htmlspecialchars((string) $page['icon'], ENT_QUOTES, 'UTF-8'); ?>" aria-hidden="true"></i>
                            <?php echo htmlspecialchars((string) $page['label'], ENT_QUOTES, 'UTF-8'); ?>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </nav>
        <div class="sidebar-footer">
            <div class="user-snippet">
                <div class="avatar-small" aria-hidden="true">
                    <i class="<?php echo htmlspecialchars($avatarIcon, ENT_QUOTES, 'UTF-8'); ?>"></i>
                </div>
                <div class="user-snippet-info">
                    <div class="user-name"><?php echo htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8'); ?></div>
                    <div class="user-status"><i class="<?php echo htmlspecialchars($statusIcon, ENT_QUOTES, 'UTF-8'); ?>" aria-hidden="true" style="font-size: 0.5rem"></i> <?php echo htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8'); ?></div>
                </div>
            </div>
            <form method="POST" action="../logout.php" style="display:inline;" data-loader="true" data-logout-confirm="true">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                <button type="submit" class="logout-link">
                    <i class="<?php echo htmlspecialchars($logoutIcon, ENT_QUOTES, 'UTF-8'); ?>" aria-hidden="true"></i> Logout
                </button>
            </form>
        </div>
    </aside>
<?php
}
