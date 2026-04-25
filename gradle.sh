#!/bin/bash

wget -q https://services.gradle.org/distributions/gradle-8.2-bin.zip -O gradle.zip
unzip -q gradle.zip
chmod +x gradle-8.2/bin/gradle
gradle-8.2/bin/gradle -p app assembleDebug

echo "Build complete!"
