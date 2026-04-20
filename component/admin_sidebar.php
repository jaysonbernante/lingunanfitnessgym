<?php
// component/admin_sidebar.php
// Dynamically determine base path for sidebar links
$currentDir = basename(dirname($_SERVER['PHP_SELF']));
$basePath = '';
if ($currentDir === 'management' || $currentDir === 'system') {
    $basePath = '../../client/';
    $iconPath = '../../../assets/ico/';
} else {
    $basePath = '';
    $iconPath = '../../assets/ico/';
}
$currentFile = isset($page) ? $page : basename($_SERVER['PHP_SELF']);
?>
<div id="loading-overlay" style="display:none;position:fixed;top:0;left:0;width:100vw;height:100vh;background:rgba(30,30,30,0.7);z-index:9999;align-items:center;justify-content:center;">
  <div style="color:#fff;font-size:2rem;">Loading...</div>
</div>
<button class="sidebar-toggle" id="sidebarToggle" aria-label="Open sidebar" style="position:fixed;top:15px;left:200px;z-index:1001;background:none;border:none;outline:none;cursor:pointer;display:none;">
  <span id="sidebarToggleIcon">
    <svg width="28" height="28" viewBox="0 0 28 28" fill="none" xmlns="http://www.w3.org/2000/svg">
      <rect width="28" height="28" rx="8" />
      <path d="M10 8L16 14L10 20" stroke="#FFD900" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
      <path d="M14 8L20 14L14 20" stroke="#ffd000" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
    </svg>
  </span>
</button>
<div class="sidebar-backdrop" id="sidebarBackdrop" style="display:none;position:fixed;top:0;left:0;width:100vw;height:100vh;background:rgba(0,0,0,0.3);z-index:1000;"></div>
<aside class="sidebar">
  <div class="sidebar-section">
    <div class="sidebar-title">Admin</div>
    <ul>
      <li>
        <a href="<?php echo $basePath . 'dashboard.php'; ?>" class="<?php echo $currentFile === 'dashboard' ? 'active' : ''; ?>">
          <span style="display:inline-block;vertical-align:middle;margin-right:7px;">
            <svg width="28" height="28" viewBox="0 0 28 28" fill="none" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink">
              <rect width="28" height="28" fill="url(#pattern0_93_88)"/>
              <defs>
                <pattern id="pattern0_93_88" patternContentUnits="objectBoundingBox" width="1" height="1">
                  <use xlink:href="#image0_93_88" transform="scale(0.0111111)"/>
                </pattern>
                <image id="image0_93_88" width="90" height="90" preserveAspectRatio="none" xlink:href="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAFoAAABaCAYAAAA4qEECAAAACXBIWXMAAAsTAAALEwEAmpwYAAAA8UlEQVR4nO3WsQ0DMAwDQa+YmTmOhmDGiITcA+6pq/yeJEmSJEnSHzf59BfvHdkDOqAL+sBhs2wP6IAu6AOHzbI9oAO6oA8cNsv2gA7ogj5w2CzbAzqgC/rAYbNsD+iALugDh82yPaADuqAPHDbL9oAO6II+cNgs2wM6oAv6wGGzbA/ogC5oSZKkn7XtOzXL9oAO6II+cNgs2wM6oAv6wGGzbA/ogC7oA4fNsj2gA7qgDxw2y/aADuiCPnDYLNsDOqAL+sBhs2wP6IAu6AOHzbI9oAO6oA8cNsv2gA7ogj5w2CzbAzqgC1qSJEmSJOmd6gutDRZX4KRklAAAAABJRU5ErkJggg=="/>
              </defs>
            </svg>
          </span>
          Dashboard
        </a>
      </li>
    </ul>
  </div>
  <div class="sidebar-section">
    <div class="sidebar-title">Management</div>
    <ul>
      <li><a href="<?php echo $basePath . 'management/member.php'; ?>" class="<?php echo $currentFile === 'member' ? 'active' : ''; ?>">
        <span style="display:inline-block;vertical-align:middle;margin-right:7px;">
        <svg width="28" height="28" viewBox="0 0 28 28" fill="none" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink">
        <rect width="28" height="28" fill="url(#pattern0_93_76)"/>
        <defs>
        <pattern id="pattern0_93_76" patternContentUnits="objectBoundingBox" width="1" height="1">
        <use xlink:href="#image0_93_76" transform="scale(0.0111111)"/>
        </pattern>
        <image id="image0_93_76" width="90" height="90" preserveAspectRatio="none" xlink:href="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAFoAAABaCAYAAAA4qEECAAAACXBIWXMAAAsTAAALEwEAmpwYAAAESUlEQVR4nO2cS4hcRRSGT3wrKD4goii+VuJKBN0ZNaIBM9Pn9DgrQUE0CwV1IyIiKm5cCBoFRQV1JeJKFAWfPfecnjwgIYnGxyhGJzrM9Dl3YnwwBmK8Uu08WmV0mrl9q1K3Pvg3s7g956Omuqrm1gFIJBKJRCKRSCQSiUR/dNp4iQreboIvKVNLGSdV6IAKHTahX5XxO/dzE3pUx0Yu7/Px9WZqx8ZTTOhuE9xpQkU/UaEPZ2XkMt81BE1RwBrN6A4Tsn4F/z34m7XpFt/1BMlP2/EsZXpvdYJ7RjbjEcuw4buuoMhbdJ4xfV2W5J6YMt1j0hiaHqe1UGcOyk1nKNOXA5D8zxxSpletPXwu1HFONsZ3KpDcM6XQVJ7hlVAncqFbq5TcM3/PHhhvXgB14Kt3N5yojD/4ED2fMagDyninR8nd5G28AWJHGbf6Fq2CH0DMzGwdOtu35L9E0+GDW0bPhFgxbqBvyYuJeQepjE96F7wQxqcgVpTxY++CF6YPxvchVpTxU9+Cl4LfQKwo05R/wYsxiBVzx5j+BS/kEMSKCf4SgOBulOlniBUTmvAtuCcTECta4gH/qke04EcQKyr0sG/BPXkMYiXn4auCGdFZ82qIGWX8wrdkE9pXFI8cAzFjgs/4Fq1Mz0LMmDSu6f6X2rtoPKKM6yBWlOkt35IXZQu9CbGijHt9C14K7oFYUaHtAY3obRArJvRiMKKZnodYsTYNhyMaN0KsFG+MHmtCn4QwP0e/jp7Ohi4yxt3eJDPtmm41LoS6YF42LrgZ6oZ5mK+jnpeXo9g7eoIxdiqcMmaKHZuOhzqiQvdVJjrD+6GuFK11x1W0W9y3f8voyVBnrN28dpAHTd1nc2O97zqDQBkfGpToXPBB3/WFdgPg9QGIfs0923d9Ae4acXOJkl+o7SpjJZQlekUfVmcsia4GS6KrwZLoarAkuhosiR48xo31pS3v0m7wv3p19N+nY/ngTvfMZT6unuTbNpzmGpuUJ3npPqG1h0+FulMUsKbDdPMgryyr4PedrDFSy6349DitNaa7qnzhUYU+d58Zff+O3DVBEdxkQm/PN6CqRPC/hHePZLFtQg+4BlkQAzPSvFgF73WFKdMfvuT+z0j/zHUaM8Er4GgiH8NLVfCJwO6rFCvMhPvdXQ0QIu6LJhdszv9JFnEE266mYL5EO1nzRvfmj38xNJgw7nY1ehP8Y6txugm94l2EVDbCX3Y1VypZMzonjHfoqNK45ehs1ji/upUE46Tvos2f7EnnYOCNp8o9j6CjNLjn29ZtJ0V9o8pCCdPTA3vd1ueOzgKLCv4+kJ2lCj3nuzgLLKXfV+yeFTPO+S7MQgvjXKnv9OXSvN57URJoMrquNNHG9Lj3giTYlNclwXXUCqCgIsS4HiSliY76LENWGaZdpYn23B23CDkqtL800WnFQcuHca400YlEIpFIJBKJRCIBHvgTZaL7c2FGi5MAAAAASUVORK5CYII="/>
        </defs>
        </svg>
        </span>
        Member
      </a></li>
      <li><a href="<?php echo $basePath . 'management/wallet.php'; ?>" class="<?php echo $currentFile === 'wallet' ? 'active' : ''; ?>">
        <span style="display:inline-block;vertical-align:middle;margin-right:7px;">
        <svg width="28" height="36" viewBox="0 0 28 36" fill="none" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink">
        <rect width="28" height="36" fill="url(#pattern0_93_77)"/>
        <defs>
        <pattern id="pattern0_93_77" patternContentUnits="objectBoundingBox" width="1" height="1">
        <use xlink:href="#image0_93_77" transform="matrix(0.0111111 0 0 0.00864198 0 0.111111)"/>
        </pattern>
        <image id="image0_93_77" width="90" height="90" preserveAspectRatio="none" xlink:href="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAFoAAABaCAYAAAA4qEECAAAACXBIWXMAAAsTAAALEwEAmpwYAAACo0lEQVR4nO2cy2oUQRSGGxeKKK4V3Yku1ZW3heANsnLOGZm3UCF5AV8hiYK39xBcyDj1D0l2uhAVlypoV7UKTiRxVVLRRYSZSTOZ6Wqr/w++3TDp81GpqizSWUYIIYQQQgghhBBC/sH125cd9LE18tZB1x3U/29a6Hdr5P6Hlc7+rG7kfTluIc9jR3LTDG7kYVYn8l7rojVaxA7jpq3Rn97f3ZPVZiWnGBk1C53aduG2aY0+yOqA6+mVJANDvznIcm0Ow3C7GP6wsmGNzufdzuHYz5gE1ui7Eb9y87GfLSkcZDAsNFfylBm1xzH0lBl9WsvCtH9Woxl9cofDUBZsT4/EfsYkiH0Nc9W7bqFr1uid90/n9jE0KtDIq6Krxxga1cSuZGVHHxTxzY3eZmjMXmtklaFRhTJgaFQjQ4OhfUoyNBjapyRDg6F9SjI0GNqnJEODoX1KMjQY2qckQ4OhfUoyNMoqG87oYtGTs5+fXT8QLMyNcw6y5KCbDI3dayEfv5ibp0bNmENPh88wNHa3ksdF3h573MpmaOyg0cXys8oyQ2Myw55cdtYCep6hMZl5t3Ow7KzhswyN2Ycu1uYOMTQmk1sHqlKWys5qjd5jaEzsZri67TRn3m+dsUZ+MTQmN/wxMi7238ifxn0HQ6P8yg735LAPhwMy+LUvF8J2MW4lMzSqlaHB0D4lGRoM7VOSocHQPiUZGgztU5KhwdA+JRkayYQe/mKUppnFetVP08xmjYM8iT2ka0LoAu1rsYd0kbVGf2RV4CC9ZoeW2f/TfcAaOfnnhXzxh3YRLCC3Kgm9FbvXvtTI2EZf+tedvZWF3or9on3CGu02KXKx0jmaxcKZ1lUHfWShb9K7Z8vAGl0J20XlK5kQQgghhBBCCCEkqye/AeOgn2vgr5iCAAAAAElFTkSuQmCC"/>
        </defs>
        </svg>
        </span>
        Monitor Wallet
      </a></li>
      <li><a href="<?php echo $basePath . 'management/staff.php'; ?>" class="<?php echo $currentFile === 'staff' ? 'active' : ''; ?>">
        <span style="display:inline-block;vertical-align:middle;margin-right:7px;">
        <svg width="28" height="28" viewBox="0 0 28 28" fill="none" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink">
        <rect width="28" height="28" fill="url(#pattern0_93_78)"/>
        <defs>
        <pattern id="pattern0_93_78" patternContentUnits="objectBoundingBox" width="1" height="1">
        <use xlink:href="#image0_93_78" transform="scale(0.0111111)"/>
        </pattern>
        <image id="image0_93_78" width="90" height="90" preserveAspectRatio="none" xlink:href="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAFoAAABaCAYAAAA4qEECAAAACXBIWXMAAAsTAAALEwEAmpwYAAAEB0lEQVR4nO2cS4sUVxSAK2jUnUiQ6MRATPQPmB8gKhGjzvQ5A71NiIuAEBN04WSpbl1lp/hauQkEFEMWyaKnz2nGByGjYjZuXBgc+94eX2MW4qPk1EwzTdvdY1WXfW6X54Ozmbmv+ricqlv3dkWRYRiGYRiGYRiGYRiGYRiG0Ys7lW9XOYafHONVz/jUM8YWKPHUMV5xhD/e/mPXyr4kNyq4wTPcCOCi4qCD4Lq4yjyTTTKmkp1pZku6UB88D1fUCQ9kEJ3kZPXB+yEKR3A5tWjPMKc9cD90AXMZRGsPGocyTDSb6LhIYaLZRMdFChPN771ouD0f+pKKLPpSHEcfSHiG37UlFVa0I9i72F9pVFtSIUU7xoetL2HiW+UVjmBWW1ThRHuGsx36PKctqnCi64S72vt0DF9riyqUaMf4QFJFe5/x399/6Agb2rIKI9oznOraL+FpbVmFEd1g/Kpbv40a7NSWVRTRPq5sXd6tX/mfJ6hrCxt60Y7gxFv0fVJb2NCL9lXcvmTfVNqhLSwo0Y7wlSfYX58a3XSfxz9vxuOr8NEj3rOmNR78VV6ddgxSp70dabu1L+lbxpCMpaiiPcM/USB4gusFntEwm/ngSI7IGOTZvMAzOpnVrtMqb1D42vg2z3BPW/AARC/kaoZfZGUXDYj41/Iyz3jEMbzQljsw0S2zu9qYKn8SvWPuXx792BH8qS1VUXQS3tXGd78Tw1Hz0Q9mtIWGIHoxlXR4cZSVZMUoqYLgpbbMYEQvCodrM9XRjVGfzFZLn3qGmrbEYEUvyP5P9gSzSpa60oa2wPBFM16I+sQRXNQWGLzoOuF3fYtm2KctMGjR8ox7j8pr+xUt7zcc43NticGK9oyT/UpuIs/o2hKDFe0ID0Y54QgOaUsMVnS9Bl/06iOe3005JtFr10WQtrQlBioabvRqf6ZS+swT8uLsh2tucnzzEmO6qS0yQNF4tFvbDcZvHOGTN+oQ/i+/Bus6Jpn9AcgMSrSr4pZOOyaO4PySdQl/kyeNN8cEX2qLDEq0Y7jbvhr0VdyeZoWXlG3bX0xWiQx3tWUGI1pyaVP0wuHF41leBkkdqdt8OTUvGv/VlhmSaMm3ZyQXe8LpHNqaTtpiOJulviN45ql02NfGRpJgnEj+llN5XdFhxUSH65vIsbyJ9vM35vXt11evlNflVd5E80LUxkbar0+23HIrb6KxRyqAn3Msb6J98+YmOTbNzTBFeRPNgw8TzSY6fs9F24dRfMpwhI9Ti7ZP/eBgPvUj33PTHrgfsmgw/JBatPyqNZQzx34YgnA688ms5AODJjt+G8l9H+6UmS3fc5P8YzdIbAmYc4RTki7yPGNoGIZhGIZhGIZhGIZhGIYRFZPXq4HQQnHTNB8AAAAASUVORK5CYII="/>
        </defs>
        </svg>
        </span>
        Staff
      </a></li>
      <li><a href="<?php echo $basePath . 'management/visitorLog.php'; ?>" class="<?php echo $currentFile === 'visitorLog' ? 'active' : ''; ?>">
        <span style="display:inline-block;vertical-align:middle;margin-right:7px;">
        <svg width="28" height="28" viewBox="0 0 28 28" fill="none" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink">
        <rect width="28" height="28" fill="url(#pattern0_93_79)"/>
        <defs>
        <pattern id="pattern0_93_79" patternContentUnits="objectBoundingBox" width="1" height="1">
        <use xlink:href="#image0_93_79" transform="scale(0.0111111)"/>
        </pattern>
        <image id="image0_93_79" width="90" height="90" preserveAspectRatio="none" xlink:href="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAFoAAABaCAYAAAA4qEECAAAACXBIWXMAAAsTAAALEwEAmpwYAAAB8klEQVR4nO3au07DMBTG8YqtPAf0LWDgsoHwqdTn4R24CAo8C6U26sRMmZEYsLmqwFpkihBLJScOTkL+P8lSp6T+kjjx0Wm1AAAAAAAAAAAAAADV8mBk0xl1arXcOCNvflitxs7IyYPpbuQ97t3V1qI16tAa9eKMTOs2rJFnq9XB7ajXjgrYatVxRg0DTnphL7rLOY5/XHZYhQSu1XH+kIfdVWvkKcPVfbKXOyuhx59OdxecVh9lh1TI0PLu55M9ZK06WUL+dWUf70fbSwQdKHC5mBO2DJq3dMhRK9+LL/YK76yHnOt21GtbLfv+pVLLgL+eerWX62XotJwV8Cf6mU/cNN+fcJGPkhqXPY/Kc0ZN4u9oNSl7Ho0I2mp5LXsezVg6jFyXPY/K81vtUndKTeFrF9Fr9FDW6ljrsEXVLkL52kXE3Xxe9w2LTfVE+gJRo7fgOueWOg9fIPLBZQn53xSVdMKgf+5sLYOAkM9D7+R6LB05ahdF8LULv632n22z72w1mf2WfuiLrw61jqjaBQAAAAAAAAAgGo3oMq9+TSO6Sxk4jeiSZtCILtUOmkZ0yTpnGtHdXwZMI3oN0IieCI3oidCIngiN6InQiJ4IjeiKRnSXKnAa0SXNoBFd/l/QHo3oidGIDgAAAAAAAAAAgFYyn+PF7sV64oEvAAAAAElFTkSuQmCC"/>
        </defs>
        </svg>
        </span>
        Visitor Log
      </a></li>

      <li><a href="<?php echo $basePath . 'system/revenue.php'; ?>" class="<?php echo $currentFile === 'revenue' ? 'active' : ''; ?>">
        <span style="display:inline-block;vertical-align:middle;margin-right:7px;">
        <svg width="28" height="28" viewBox="0 0 28 28" fill="none" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink">
        <rect width="28" height="28" fill="url(#pattern_revenue)"/>
        <defs>
        <pattern id="pattern_revenue" patternContentUnits="objectBoundingBox" width="1" height="1">
        <use xlink:href="#image_revenue" transform="scale(0.0111111)"/>
        </pattern>
        <image id="image_revenue" width="90" height="90" preserveAspectRatio="none" xlink:href="data:image/png;base64,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"/>
</defs>
</svg>
        </span>
        Revenue
      </a></li>

      <li><a href="<?php echo $basePath . 'management/report.php'; ?>" class="<?php echo $currentFile === 'report' ? 'active' : ''; ?>">
        <span style="display:inline-block;vertical-align:middle;margin-right:7px;">
        <svg width="28" height="28" viewBox="0 0 28 28" fill="none" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink">
        <rect width="28" height="28" fill="url(#pattern0_93_80)"/>
        <defs>
        <pattern id="pattern0_93_80" patternContentUnits="objectBoundingBox" width="1" height="1">
        <use xlink:href="#image0_93_80" transform="scale(0.0111111)"/>
        </pattern>
        <image id="image0_93_80" width="90" height="90" preserveAspectRatio="none" xlink:href="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAFoAAABaCAYAAAA4qEECAAAACXBIWXMAAAsTAAALEwEAmpwYAAACyElEQVR4nO3du24TQRQG4FMBb8D1OagoqIBIhMxBcs0LcCkTXozW8k4uogpIVDS0eGYbFBBLZTSJJRTL1rLemT17jv9f+hsX483nk3E8K8VECIIgCIIgavJt+upW8O5d8Pwxev4ZPS+kGir3J3o+qWfuIVlKPeUH0bvPkrhxbd3v+tg9ISuTPE5ktoWdtgt5TLaPvdyTR4DJtrGjdxfyiPyvFf8yiS0O66937t2jlhe/CZV7TtoiDRtXmq7JJPYYoU1ijxXaHPaYoU1hjx3aDLYGaBPYWqDVY2uCVo2tDVottkZoldhaodVha4ZWha0dWg22BeiU6A8etx6xSt6DlIaNqz1+ca8cNp/k1et0cSPA9dd62O/n2Yyd7q7nk+t8YeKwi1WMhF1qsvPqdbooedw4YAHtAW2qBGgGtKUSoBnQlkqAZkBbKgGaAW2pBGgGtKUSoBnQlkrGoJt0pjyfTu6kXp4vXz1WbP3lOfZOQTfBu73V50mPZcJeu3707mhnoMPlVB3sb3qu+ezl03STtMT638/2b+8EdGhB7ovdtn669bUL0M26X+dN2WIbaV2/9u69deimC/IW2K3rd3nhSCpDbBfbbiP/s37XrYikUnKSg3d7PaYx6ySrhA4dJm2bqSwxyeqgQ2G4ksiaoJvSW0GJ7UIVdMgwaaXfPC1ANxknrfSfg6qhD4f+ANJzfZ3Q6YSMBv5I3Xd9U9DzgodEOdZXB52OIGngY8+c24Ua6KvJc0dhxndTl2e/mQ/yi66vA9pSCdAMaEslQDOgLZUAzYC2VAI0A9pSCdAMaEslQDOgLZUAzYC2VJKDHtm/nvflGir+IQat58sUOAO0O5ODrvitNEAcqLV3r8Wgv354djNW7pM0Qizdis8XXyY3SPwrnCxjV3xen07u0xiSJnte8Zu0j9l4g3QXoeLTtF2ITzKCIAiCIAh1yV92nuQa6ivaYQAAAABJRU5ErkJggg=="/>
        </defs>
        </svg>
        </span>
        Transaction
      </a></li>

    </ul>
  </div>
  <div class="sidebar-section">
    <div class="sidebar-title">System</div>
    <ul>
      <li><a href="<?php echo $basePath . 'system/RFID.php'; ?>" class="<?php echo $currentFile === 'RFID' ? 'active' : ''; ?>">
        <span style="display:inline-block;vertical-align:middle;margin-right:7px;">
        <svg width="28" height="28" viewBox="0 0 28 28" fill="none" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink">
        <rect width="28" height="28" fill="url(#pattern0_93_87)"/>
        <defs>
        <pattern id="pattern0_93_87" patternContentUnits="objectBoundingBox" width="1" height="1">
        <use xlink:href="#image0_93_87" transform="scale(0.0111111)"/>
        </pattern>
        <image id="image0_93_87" width="90" height="90" preserveAspectRatio="none" xlink:href="data:image/png;base64,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"/>
        </defs>
        </svg>
        </span>
        RFID
      </a></li>
      
      <li><a href="<?php echo $basePath . 'system/Ecommerce.php'; ?>" class="<?php echo $currentFile === 'Ecommerce' ? 'active' : ''; ?>">
        <span style="display:inline-block;vertical-align:middle;margin-right:7px;">
        <svg width="28" height="28" viewBox="0 0 28 28" fill="none" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink">
        <rect width="28" height="28" fill="url(#pattern0_93_83)"/>
        <defs>
        <pattern id="pattern0_93_83" patternContentUnits="objectBoundingBox" width="1" height="1">
        <use xlink:href="#image0_93_83" transform="scale(0.0111111)"/>
        </pattern>
        <image id="image0_93_83" width="90" height="90" preserveAspectRatio="none" xlink:href="data:image/png;base64,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"/>
        </defs>
        </svg>
        </span>
        E-commerce
      </a></li>
      
      <li><a href="<?php echo $basePath . 'system/backup.php'; ?>" class="<?php echo $currentFile === 'backup' ? 'active' : ''; ?>">
        <span style="display:inline-block;vertical-align:middle;margin-right:7px;">
        <svg width="28" height="28" viewBox="0 0 28 28" fill="none" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink">
        <rect width="28" height="28" fill="url(#pattern0_93_82)"/>
        <defs>
        <pattern id="pattern0_93_82" patternContentUnits="objectBoundingBox" width="1" height="1">
        <use xlink:href="#image0_93_82" transform="scale(0.0111111)"/>
        </pattern>
        <image id="image0_93_82" width="90" height="90" preserveAspectRatio="none" xlink:href="data:image/png;base64,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"/>
        </defs>
        </svg>
        </span>
        Backup
      </a></li>
    </ul>
  </div>
  
  <a class="logout" href="#" id="logoutBtn">
    <span style="display:inline-block;vertical-align:middle;margin-right:7px;">
      <svg width="30" height="30" viewBox="0 0 30 30" fill="none" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink">
        <rect width="30" height="30" fill="url(#pattern0_93_81)"/>
        <defs>
          <pattern id="pattern0_93_81" patternContentUnits="objectBoundingBox" width="1" height="1">
            <use xlink:href="#image0_93_81" transform="scale(0.0111111)"/>
          </pattern>
          <image id="image0_93_81" width="90" height="90" preserveAspectRatio="none" xlink:href="data:image/png;base64,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"/>
</defs>
</svg>
    </span>
    LOGOUT
  </a>
</aside>

<!-- ── Logout Confirmation Modal ─────────────────────────────────────── -->
<div id="logoutModal" style="display:none;position:fixed;top:0;left:0;width:100vw;height:100vh;background:rgba(0,0,0,0.55);z-index:10000;align-items:center;justify-content:center;">
  <div style="background:#1e1e2e;border-radius:12px;padding:36px 32px;max-width:380px;width:90%;box-shadow:0 8px 32px rgba(0,0,0,0.5);text-align:center;">
    
    <div style="font-size:1.2rem;font-weight:700;color:#fff;margin-bottom:8px;">Confirm Logout</div>
    <div style="color:#aaa;font-size:0.95rem;margin-bottom:28px;">Are you sure you want to log out?</div>
    <div style="display:flex;gap:12px;justify-content:center;">
      <button id="logoutCancel" style="flex:1;padding:10px 0;border-radius:7px;border:1px solid #444;background:#2a2a3e;color:#ccc;font-size:0.95rem;cursor:pointer;">Cancel</button>
      <a id="logoutConfirm" href="<?php echo $basePath . 'logout.php'; ?>" style="flex:1;padding:10px 0;border-radius:7px;border:none;background:#e53935;color:#fff;font-size:0.95rem;cursor:pointer;text-decoration:none;display:flex;align-items:center;justify-content:center;font-weight:600;">Logout</a>
    </div>
  </div>
</div>

<!-- ── Auto-logout Warning Modal ─────────────────────────────────────────── -->
<div id="timeoutWarnModal" style="display:none;position:fixed;top:0;left:0;width:100vw;height:100vh;background:rgba(0,0,0,0.55);z-index:10001;align-items:center;justify-content:center;">
  <div style="background:#1e1e2e;border-radius:12px;padding:36px 32px;max-width:400px;width:90%;box-shadow:0 8px 32px rgba(0,0,0,0.5);text-align:center;">
    
    <div style="font-size:1.2rem;font-weight:700;color:#fff;margin-bottom:8px;">Session Expiring</div>
    <div style="color:#aaa;font-size:0.95rem;margin-bottom:6px;">You will be automatically logged out in</div>
    <div id="timeoutCountdown" style="font-size:2rem;font-weight:800;color:#f57c00;margin-bottom:20px;">60</div>
    <div style="color:#aaa;font-size:0.9rem;margin-bottom:24px;">seconds due to inactivity.</div>
    <button id="timeoutStayBtn" style="width:100%;padding:11px 0;border-radius:7px;border:none;background:#1976d2;color:#fff;font-size:1rem;cursor:pointer;font-weight:600;">Stay Logged In</button>
  </div>
</div>

<script>
  // Sidebar loading overlay and responsive toggle
  document.addEventListener('DOMContentLoaded', function() {
    var sidebar = document.querySelector('.sidebar');
    var toggle = document.getElementById('sidebarToggle');
    var backdrop = document.getElementById('sidebarBackdrop');
    // Show loading overlay on sidebar link click
    if (sidebar) {
      sidebar.querySelectorAll('a').forEach(function(link) {
        link.addEventListener('click', function(e) {
          if (link.getAttribute('href') && link.getAttribute('href') !== '#' && !link.classList.contains('logout')) {
            document.getElementById('loading-overlay').style.display = 'flex';
          }
        });
      });
    }
    // Responsive sidebar toggle
    function updateSidebarToggle() {
      if (window.innerWidth <= 900) {
        if (toggle) toggle.style.display = 'block';
        if (sidebar) sidebar.classList.remove('open');
        if (backdrop) backdrop.style.display = 'none';
      } else {
        if (toggle) toggle.style.display = 'none';
        if (sidebar) sidebar.classList.remove('open');
        if (backdrop) backdrop.style.display = 'none';
      }
    }
    if (toggle && sidebar && backdrop) {
      toggle.addEventListener('click', function() {
        sidebar.classList.toggle('open');
        if (sidebar.classList.contains('open')) {
         
          toggle.setAttribute('aria-label', 'Close sidebar');
          document.getElementById('sidebarToggleIcon').innerHTML = `
            <svg width="28" height="28" viewBox="0 0 28 28" fill="none" xmlns="http://www.w3.org/2000/svg">
              <rect width="28" height="28" rx="8" />
              <path d="M18 8L12 14L18 20" stroke="#FFD900" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
              <path d="M14 8L8 14L14 20" stroke="#FFD900" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
          `;
        } else {
          backdrop.style.display = 'none';
          toggle.setAttribute('aria-label', 'Open sidebar');
          document.getElementById('sidebarToggleIcon').innerHTML = `
            <svg width="28" height="28" viewBox="0 0 28 28" fill="none" xmlns="http://www.w3.org/2000/svg">
              <rect width="28" height="28" rx="8" />
              <path d="M10 8L16 14L10 20" stroke="#FFD900" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
              <path d="M14 8L20 14L14 20" stroke="#FFD900" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
          `;
        }
      });
      backdrop.addEventListener('click', function() {
        sidebar.classList.remove('open');
        backdrop.style.display = 'none';
        toggle.setAttribute('aria-label', 'Open sidebar');
        document.getElementById('sidebarToggleIcon').innerHTML = `
          <svg width="28" height="28" viewBox="0 0 28 28" fill="none" xmlns="http://www.w3.org/2000/svg">
            <rect width="28" height="28" rx="8" />
            <path d="M10 8L16 14L10 20" stroke="#FFD900" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
            <path d="M14 8L20 14L14 20" stroke="#FFD900" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
        `;
      });
    }
    window.addEventListener('resize', updateSidebarToggle);
    updateSidebarToggle();

    // ── Logout confirmation modal ──────────────────────────────────────────
    var logoutBtn    = document.getElementById('logoutBtn');
    var logoutModal  = document.getElementById('logoutModal');
    var logoutCancel = document.getElementById('logoutCancel');
    if (logoutBtn && logoutModal) {
      logoutBtn.addEventListener('click', function(e) {
        e.preventDefault();
        logoutModal.style.display = 'flex';
      });
      logoutCancel.addEventListener('click', function() {
        logoutModal.style.display = 'none';
      });
      logoutModal.addEventListener('click', function(e) {
        if (e.target === logoutModal) logoutModal.style.display = 'none';
      });
    }

    // ── Auto-logout after 20 min of inactivity (client-side guard) ─────────
    var IDLE_TIMEOUT   = 20 * 60 * 1000; // 20 minutes in ms
    var WARN_BEFORE    = 60 * 1000;       // show warning 60s before logout
    var logoutUrl      = document.getElementById('logoutConfirm') ? document.getElementById('logoutConfirm').href : 'logout.php';
    var warnModal      = document.getElementById('timeoutWarnModal');
    var countdownEl    = document.getElementById('timeoutCountdown');
    var stayBtn        = document.getElementById('timeoutStayBtn');
    var idleTimer      = null;
    var warnTimer      = null;
    var countdownTimer = null;
    var warnSeconds    = 60;

    function resetIdleTimer() {
      // If warning is showing, hide it first
      if (warnModal && warnModal.style.display === 'flex') return; // don't reset during countdown
      clearTimeout(idleTimer);
      clearTimeout(warnTimer);
      idleTimer = setTimeout(function() {
        // Show warning modal
        if (warnModal) {
          warnSeconds = 60;
          if (countdownEl) countdownEl.textContent = warnSeconds;
          warnModal.style.display = 'flex';
          clearInterval(countdownTimer);
          countdownTimer = setInterval(function() {
            warnSeconds--;
            if (countdownEl) countdownEl.textContent = warnSeconds;
            if (warnSeconds <= 0) {
              clearInterval(countdownTimer);
              window.location.href = logoutUrl;
            }
          }, 1000);
        } else {
          window.location.href = logoutUrl;
        }
      }, IDLE_TIMEOUT - WARN_BEFORE);
    }

    if (stayBtn) {
      stayBtn.addEventListener('click', function() {
        clearInterval(countdownTimer);
        if (warnModal) warnModal.style.display = 'none';
        resetIdleTimer();
      });
    }

    // Reset on any user activity
    ['mousemove','mousedown','keydown','scroll','touchstart','click'].forEach(function(evt) {
      document.addEventListener(evt, resetIdleTimer, true);
    });

    resetIdleTimer(); // start the timer
  });
</script>
