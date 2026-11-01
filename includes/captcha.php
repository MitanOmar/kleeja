<?php
/**
 *
 * @package Kleeja
 * @copyright (c) 2007 Kleeja.net
 * @license ./docs/license.txt
 *
 */

// Fix bug with path of font When using versions of the GD library lower than 2.0.18
if (function_exists('putenv')) {
    @putenv('GDFONTPATH=' . realpath('.'));
} elseif (function_exists('ini_set')) {
    @ini_set('GDFONTPATH', realpath('.'));
}

// When any body request this file , he will see an image ..
kleeja_cpatcha_image();

exit();

//
//this function will just make an image
//source : http://webcheatsheet.com/php/create_captcha_protection.php
//
function kleeja_cpatcha_image(): void
{
    //4 random characters, without the ones that look alike (0 and o, 1 and l and i, 9 and g and q)
    $chars = '23456789abcdefhjkmnprstuvwxyz';
    $security_code = '';

    for ($i = 0; $i < 4; ++$i) {
        $security_code .= $chars[random_int(0, strlen($chars) - 1)];
    }

    //Set the session to store the security code
    $_SESSION['klj_sec_code'] = $security_code;

    //Set the image width and height, 38px is the height of a form field
    $width = 160;
    $height = 38;

    //Create the image resource
    $image = imagecreatetruecolor($width, $height);

    //The Kleeja brand colours (kleeja.net/branding): navy and coral on white,
    //with lighter steps of both for the noise
    $white = imagecolorallocate($image, 255, 255, 255);
    $navy = imagecolorallocate($image, 11, 31, 58); // #0B1F3A
    $navy_50 = imagecolorallocate($image, 246, 247, 248); // #F6F7F8
    $navy_300 = imagecolorallocate($image, 187, 192, 200); // #BBC0C8
    $coral = imagecolorallocate($image, 244, 91, 105); // #F45B69
    $coral_300 = imagecolorallocate($image, 250, 183, 189); // #FAB7BD

    imagefill($image, 0, 0, $white);

    //The Kleeja mark sits on its own panel at the start of the image. The mark is 95% of
    //the logo file, so 26px gives a 24.7px mark (the brand minimum is 24px), and the panel
    //keeps a clear space of a quarter of its height on every side
    $code_x = 0;
    $logo_file = PATH . 'images/logo.png';

    if (function_exists('imagecreatefrompng') && is_readable($logo_file) && ($logo = @imagecreatefrompng($logo_file))) {
        $logo_size = 26;
        $code_x = $height;

        imagefilledrectangle($image, 0, 0, $code_x - 1, $height - 1, $navy_50);
        imagecopyresampled(
            $image,
            $logo,
            intdiv($code_x - $logo_size, 2),
            intdiv($height - $logo_size, 2),
            0,
            0,
            $logo_size,
            $logo_size,
            imagesx($logo),
            imagesy($logo),
        );
    }

    //Nothing below may enter the clear space of the mark
    imagesetclip($image, $code_x, 0, $width - 1, $height - 1);
    imageantialias($image, true);

    //Sprinkle some dots behind the code
    for ($i = 0; $i < 60; $i++) {
        imagesetpixel($image, rand($code_x, $width - 1), rand(0, $height - 1), $i % 2 ? $navy_300 : $coral_300);
    }

    //Add randomly generated string in navy to the image
    if (function_exists('imagettftext')) {
        //
        // We figure a bug that happens when you add font name without './' before it ..
        // he search in the Linux fonts cache , but when you add './' he will know it's our font.
        //
        $font = dirname(__FILE__) . '/arial.ttf';
        $font_size = 17;
        $slot = ($width - $code_x - 16) / strlen($security_code);

        //One character at a time, each one turned and moved a little
        foreach (str_split($security_code) as $i => $char) {
            $angle = rand(-12, 12);
            $box = imagettfbbox($font_size, $angle, $font, $char);
            $center_x = $code_x + 8 + $slot * ($i + 0.5) + rand(-2, 2);
            $center_y = $height / 2 + rand(-3, 3);

            imagettftext(
                $image,
                $font_size,
                $angle,
                (int) round($center_x - ($box[0] + $box[2] + $box[4] + $box[6]) / 4),
                (int) round($center_y - ($box[1] + $box[3] + $box[5] + $box[7]) / 4),
                $navy,
                $font,
                $char,
            );
        }
    } else {
        $font = imageloadfont(dirname(__FILE__) . '/arial.gdf');

        imagestring(
            $image,
            $font,
            $code_x + intdiv($width - $code_x - imagefontwidth($font) * strlen($security_code), 2) + rand(-8, 8),
            intdiv($height - imagefontheight($font), 2),
            $security_code,
            $navy,
        );
    }

    //Throw in a coral wave and some lines to make it a little bit harder for any bots to break
    $wave_y = rand(14, $height - 14);
    $amplitude = rand(4, 7);
    $phase = rand(0, 628) / 100;
    $last_y = $wave_y;

    for ($x = $code_x; $x < $width; $x += 2) {
        $y = (int) round($wave_y + $amplitude * sin($phase + ($x - $code_x) / 14));

        if ($x > $code_x) {
            imageline($image, $x - 2, $last_y, $x, $y, $coral);
        }

        $last_y = $y;
    }

    for ($i = 0; $i < 2; $i++) {
        imageline($image, $code_x, rand(0, $height - 1), $width - 1, rand(0, $height - 1), $i ? $navy_300 : $coral_300);
    }

    //Tell the browser what kind of file is come in and prevent client side caching
    header('Expires: Wed, 1 Jan 1997 00:00:00 GMT');
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Cache-Control: post-check=0, pre-check=0', false);
    header('Pragma: no-cache');
    header('Content-Type: image/png');

    //Output the newly created image in png format
    imagepng($image);
}

//<--- EOF
