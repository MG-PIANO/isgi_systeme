[Reflection.Assembly]::LoadWithPartialName('System.Drawing'); $img = new-object System.Drawing.Bitmap('public/logo.jpg'); $img.Save('build/icon.png', [System.Drawing.Imaging.ImageFormat]::Png)
