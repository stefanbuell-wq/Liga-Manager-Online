@echo off
set SERVER=u632640962@82.198.227.59
set PORT=65002
set ZIP_NAME=lmo26.zip
set REMOTE_PATH=./

echo ==========================================
echo LMO26 Deployment
echo Server: %SERVER% (Port %PORT%)
echo ==========================================
echo.

echo 1. Erstelle ZIP-Archiv...
cd ..
powershell -NoProfile -Command "$ErrorActionPreference='Stop'; $zip='%ZIP_NAME%'; if(Test-Path $zip){Remove-Item $zip -Force}; $stage=Join-Path $env:TEMP 'lmo26_pack'; if(Test-Path $stage){Remove-Item $stage -Recurse -Force -ErrorAction SilentlyContinue}; New-Item -ItemType Directory -Path $stage | Out-Null; Copy-Item -Path 'lmo26\\*' -Destination $stage -Recurse -Force -Exclude 'nul'; Compress-Archive -Path (Join-Path $stage '*') -DestinationPath $zip -Force; Remove-Item $stage -Recurse -Force -ErrorAction SilentlyContinue"
cd lmo26

if not exist "..\%ZIP_NAME%" (
    echo [ERROR] ZIP-Erstellung fehlgeschlagen!
    pause
    exit /b
)

echo.
echo 2. Lade %ZIP_NAME% hoch...
REM Bevorzugt WinSCP verwenden, falls installiert
set WINSCP_EXE=
if exist "C:\Program Files\WinSCP\WinSCP.com" set WINSCP_EXE=C:\Program Files\WinSCP\WinSCP.com
if "%WINSCP_EXE%"=="" if exist "C:\Program Files (x86)\WinSCP\WinSCP.com" set WINSCP_EXE=C:\Program Files (x86)\WinSCP\WinSCP.com
if "%WINSCP_EXE%"=="" set WINSCP_EXE=WinSCP.com

if not "%WINSCP_EXE%"=="WinSCP.com" (
    echo WinSCP gefunden: %WINSCP_EXE%
    set TMP_SCRIPT=%TEMP%\winscp_deploy.txt
    if exist "%TMP_SCRIPT%" del "%TMP_SCRIPT%"
    echo open sftp://%SERVER%:%PORT%>> "%TMP_SCRIPT%"
    echo put "..\%ZIP_NAME%" %REMOTE_PATH%>> "%TMP_SCRIPT%"
    echo call unzip -o %ZIP_NAME% -d domains/atlas-bergedorf.de/public_html/lmo26>> "%TMP_SCRIPT%"
    echo call rm %ZIP_NAME%>> "%TMP_SCRIPT%"
    echo call rm -rf domains/atlas-bergedorf.de/public_html/lmo26/scripts>> "%TMP_SCRIPT%"
    echo exit>> "%TMP_SCRIPT%"
    "%WINSCP_EXE%" /ini=nul /script="%TMP_SCRIPT%"
    set WINSCP_RC=%ERRORLEVEL%
    del "%TMP_SCRIPT%"
    if %WINSCP_RC% NEQ 0 (
        echo [WARN] WinSCP-Upload fehlgeschlagen (RC %WINSCP_RC%). Versuche scp/ssh...
        scp -P %PORT% "..\%ZIP_NAME%" %SERVER%:%REMOTE_PATH%
    )
) else (
    echo WinSCP nicht gefunden. Verwende scp/ssh...
    scp -P %PORT% "..\%ZIP_NAME%" %SERVER%:%REMOTE_PATH%
)

if %ERRORLEVEL% NEQ 0 (
    echo.
    echo [ERROR] Upload fehlgeschlagen!
    pause
    exit /b
)

echo.
echo 3. Entpacke auf Server...
ssh -p %PORT% %SERVER% "unzip -o %ZIP_NAME% -d domains/atlas-bergedorf.de/public_html/lmo26 && rm %ZIP_NAME% && rm -rf domains/atlas-bergedorf.de/public_html/lmo26/scripts"

echo.
echo 4. Lokale ZIP-Datei loeschen...
del "..\%ZIP_NAME%"

echo.
echo ==========================================
echo Deployment abgeschlossen!
echo URL: https://atlas-bergedorf.de/lmo26/
echo ==========================================
pause
