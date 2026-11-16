# Scale test evidence

The raw output of the scale test in the main README ([Scale test](../../../README.md#scale-test)). [`tests/k6/scale.sh`](../../../tests/k6/scale.sh) wrote it; [`environment.txt`](environment.txt) describes the machine and the stack.

| File                                                       | What it is                                                                                                                                         |
| ---------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------- |
| [`results.jsonl`](results.jsonl)                           | One line per size: the k6 summary, the batch latency, the Redis peak, the largest backlogs, the end-to-end latency from PostgreSQL, the read times |
| `k6-<size>.txt`                                            | The complete k6 output of the size, with its checks and HTTP statistics                                                                            |
| `provider-<size>.json`                                     | The mock provider's counters after the size: received, accepted, duplicates, failures, and the calls per channel and second                        |
| [`broadcast-lag-100000.txt`](broadcast-lag-100000.txt)     | The broadcast queue after the 100,000 notifications were delivered, and how fast it drained                                                        |
| [`redis-timeline-1000000.txt`](redis-timeline-1000000.txt) | Redis memory, broadcast queue and refused writes every 30 seconds during the 1,000,000 run                                                         |
| [`state-after-1000000.txt`](state-after-1000000.txt)       | Where the 1,000,000 notifications were when the run stopped, and how the API and `/health` answered                                                |
| [`screenshots/`](screenshots)                              | The dashboard, Horizon, the status page and the provider's counters during and after the 100,000 and 1,000,000 runs                                |

The read times in the 1,000,000 line of `results.jsonl` are of `503` answers, as Redis was full; they are not read times.

## Screenshots

| File                        | Shows                                                                                                   |
| --------------------------- | ------------------------------------------------------------------------------------------------------- |
| `100k-during-dashboard.png` | 2.5 minutes into the 100,000 run: 40,970 sent, 70,132 queued, the feed behind because of the broadcasts |
| `100k-during-horizon.png`   | The same moment in Horizon: about 66,000 deliveries and 243,810 broadcasts waiting                      |
| `100k-during-provider.png`  | The provider's counters at that moment                                                                  |
| `100k-after-horizon.png`    | After the 100,000 run: every delivery queue empty, 260,717 broadcasts still waiting                     |
| `100k-after-provider.png`   | The provider's counters: 100,000 received and accepted, no duplicate, at most 101 a second per channel  |
| `1m-2min-dashboard.png`     | 2 minutes into the 1,000,000 run, before Redis was full                                                 |
| `1m-2min-horizon.png`       | The same moment in Horizon                                                                              |
| `1m-2min-provider.png`      | The provider's counters at that moment                                                                  |
| `1m-6min-dashboard.png`     | 6 minutes in: Redis is full and the dashboard answers `503`                                             |
| `1m-6min-health.png`        | The status page at that moment: Horizon cannot run                                                      |
| `1m-12min-horizon.png`      | 12 minutes in: Horizon inactive, no worker process                                                      |
| `1m-end-health.png`         | The status page when the run stopped                                                                    |
| `1m-end-provider.png`       | The provider's counters when the run stopped: 21,077 received and accepted, no duplicate                |
