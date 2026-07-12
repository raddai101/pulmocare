-- Migration 006 — Add gradcam_path to detections
ALTER TABLE detections
  ADD COLUMN gradcam_path VARCHAR(500) NULL AFTER processing_time_ms;
