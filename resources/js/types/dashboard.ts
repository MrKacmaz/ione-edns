export type Latency = {
    p50: number | null;
    p95: number | null;
    p99: number | null;
};

export type QueueMetrics = {
    queue: string;
    pending: number;
    delayed: number;
    reserved: number;
    oldest_pending_age_seconds: number | null;
};

export type WindowMetrics = {
    window: string;
    seconds: number;
    attempts: {
        total: number;
        accepted: number;
        retryable_failure: number;
        throttled: number;
        permanent_failure: number;
    };
    notifications: { sent: number; failed: number };
    success_rate: number | null;
    failure_rate: number | null;
    latency_ms: { provider: Latency; end_to_end: Latency };
};

export type CircuitState = 'closed' | 'open' | 'half_open';

export type MetricsSnapshot = {
    generated_at: string;
    queues: QueueMetrics[];
    notifications_in_flight: Record<
        Exclude<NotificationStatus, 'sent' | 'failed' | 'cancelled'>,
        number
    >;
    windows: WindowMetrics[];
    circuits: Record<string, CircuitState>;
};

export type NotificationStatus =
    | 'waiting'
    | 'scheduled'
    | 'queued'
    | 'processing'
    | 'sent'
    | 'failed'
    | 'cancelled';

export type NotificationRow = {
    id: string;
    batch_id: string | null;
    channel: string;
    priority: string;
    status: NotificationStatus;
    recipient: string;
    attempts: number;
    last_error: string | null;
    trace_id: string | null;
    scheduled_at?: string | null;
    next_attempt_at?: string | null;
    updated_at: string;
};

export type DashboardTemplate = {
    name: string;
    channel: string;
    variables: string[];
};

export type MetricsUpdatedEvent = { metrics: MetricsSnapshot };

export type CircuitChangedEvent = { channel: string; state: CircuitState };

export type NotificationStatusChangedEvent = { notification: NotificationRow };
