# Tests

The automated checks run in GitHub Actions (`.github/workflows/lint.yml`): PHP and
JavaScript syntax, no inline scripts or handlers (the Content-Security-Policy
forbids them), and no secrets or real data in the repository.

Manual smoke test after changing anything: sign in as a student, open the dashboard,
timetable, calendar and study material (open a PDF), then sign in to the admin area
and open the launch checklist.
