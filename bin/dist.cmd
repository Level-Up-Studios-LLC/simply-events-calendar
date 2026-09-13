@echo off
setlocal enabledelayedexpansion

rd /s /q dist 2>nul
if exist dist (
    echo Failed to remove existing dist directory
    exit /b 1
)

mkdir dist
if not exist dist (
    echo Failed to create dist directory
    exit /b 1
)

robocopy . dist /E /XD node_modules src .git dist docs bin .superpowers /XF .gitignore package.json package-lock.json .npmrc phpcs.xml *.md *.WordPress.*.xml /NFL /NDL /NJH /NJS

set RC=!errorlevel!
if !RC! geq 8 (
    exit /b !RC!
)

echo Distribution created
exit /b 0
