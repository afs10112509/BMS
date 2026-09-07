; Inno Setup script — opsional (jika ISCC terpasang)
#define MyAppName "BMS Desktop"
#define MyAppVersion "1.0.0"
#define MyAppPublisher "Belawa Management System"
#define MyAppExeName "BMS Desktop.exe"

[Setup]
AppId={{A7C2E9D1-4B58-4F0A-9C3E-BMSDESKTOP2026}
AppName={#MyAppName}
AppVersion={#MyAppVersion}
AppPublisher={#MyAppPublisher}
DefaultDirName={autopf}\BMS Desktop
DefaultGroupName={#MyAppName}
DisableProgramGroupPage=yes
OutputDir=dist
OutputBaseFilename=BMS-Desktop-Setup
Compression=lzma
SolidCompression=yes
WizardStyle=modern
PrivilegesRequired=lowest
ArchitecturesInstallIn64BitMode=x64compatible

[Languages]
Name: "indonesian"; MessagesFile: "compiler:Default.isl"

[Tasks]
Name: "desktopicon"; Description: "Buat ikon di Desktop"; GroupDescription: "Pintasan:"; Flags: checkedonce

[Files]
Source: "dist\BMS Desktop.exe"; DestDir: "{app}"; Flags: ignoreversion
Source: "config.example.json"; DestDir: "{app}"; DestName: "config.json"; Flags: onlyifdoesntexist

[Icons]
Name: "{group}\{#MyAppName}"; Filename: "{app}\{#MyAppExeName}"
Name: "{autodesktop}\{#MyAppName}"; Filename: "{app}\{#MyAppExeName}"; Tasks: desktopicon

[Run]
Filename: "{app}\{#MyAppExeName}"; Description: "Jalankan BMS Desktop"; Flags: nowait postinstall skipifsilent
