#!/bin/bash
FILE=src/AppBundle/DataFixtures/supported_devices.csv
curl -o $FILE http://storage.googleapis.com/play_public/supported_devices.csv
dos2unix $FILE
