--
-- PostgreSQL database dump
--

-- Dumped from database version 16.4 (Debian 16.4-1.pgdg110+2)
-- Dumped by pg_dump version 16.4 (Debian 16.4-1.pgdg110+2)

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

--
-- Name: tiger; Type: SCHEMA; Schema: -; Owner: -
--

CREATE SCHEMA tiger;


--
-- Name: tiger_data; Type: SCHEMA; Schema: -; Owner: -
--

CREATE SCHEMA tiger_data;


--
-- Name: topology; Type: SCHEMA; Schema: -; Owner: -
--

CREATE SCHEMA topology;


--
-- Name: SCHEMA topology; Type: COMMENT; Schema: -; Owner: -
--

COMMENT ON SCHEMA topology IS 'PostGIS Topology schema';


--
-- Name: fuzzystrmatch; Type: EXTENSION; Schema: -; Owner: -
--

CREATE EXTENSION IF NOT EXISTS fuzzystrmatch WITH SCHEMA public;


--
-- Name: EXTENSION fuzzystrmatch; Type: COMMENT; Schema: -; Owner: -
--

COMMENT ON EXTENSION fuzzystrmatch IS 'determine similarities and distance between strings';


--
-- Name: pgcrypto; Type: EXTENSION; Schema: -; Owner: -
--

CREATE EXTENSION IF NOT EXISTS pgcrypto WITH SCHEMA public;


--
-- Name: EXTENSION pgcrypto; Type: COMMENT; Schema: -; Owner: -
--

COMMENT ON EXTENSION pgcrypto IS 'cryptographic functions';


--
-- Name: postgis; Type: EXTENSION; Schema: -; Owner: -
--

CREATE EXTENSION IF NOT EXISTS postgis WITH SCHEMA public;


--
-- Name: EXTENSION postgis; Type: COMMENT; Schema: -; Owner: -
--

COMMENT ON EXTENSION postgis IS 'PostGIS geometry and geography spatial types and functions';


--
-- Name: postgis_tiger_geocoder; Type: EXTENSION; Schema: -; Owner: -
--

CREATE EXTENSION IF NOT EXISTS postgis_tiger_geocoder WITH SCHEMA tiger;


--
-- Name: EXTENSION postgis_tiger_geocoder; Type: COMMENT; Schema: -; Owner: -
--

COMMENT ON EXTENSION postgis_tiger_geocoder IS 'PostGIS tiger geocoder and reverse geocoder';


--
-- Name: postgis_topology; Type: EXTENSION; Schema: -; Owner: -
--

CREATE EXTENSION IF NOT EXISTS postgis_topology WITH SCHEMA topology;


--
-- Name: EXTENSION postgis_topology; Type: COMMENT; Schema: -; Owner: -
--

COMMENT ON EXTENSION postgis_topology IS 'PostGIS topology spatial types and functions';


--
-- Name: uuid-ossp; Type: EXTENSION; Schema: -; Owner: -
--

CREATE EXTENSION IF NOT EXISTS "uuid-ossp" WITH SCHEMA public;


--
-- Name: EXTENSION "uuid-ossp"; Type: COMMENT; Schema: -; Owner: -
--

COMMENT ON EXTENSION "uuid-ossp" IS 'generate universally unique identifiers (UUIDs)';


--
-- Name: actor_type; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.actor_type AS ENUM (
    'person',
    'organization',
    'government',
    'system'
);


--
-- Name: demand_delivery_method; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.demand_delivery_method AS ENUM (
    'personal_delivery',
    'posting',
    'certified_mail'
);


--
-- Name: document_status; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.document_status AS ENUM (
    'draft',
    'finalized',
    'voided'
);


--
-- Name: evidence_status; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.evidence_status AS ENUM (
    'submitted',
    'under_review',
    'accepted',
    'rejected'
);


--
-- Name: execution_type; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.execution_type AS ENUM (
    'hand_delivery',
    'posting',
    'mailing',
    'formal_process_service',
    'mixed',
    'other'
);


--
-- Name: message_provider; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.message_provider AS ENUM (
    'sendgrid',
    'mailgun'
);


--
-- Name: party_role; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.party_role AS ENUM (
    'client',
    'owner',
    'tenant',
    'occupant',
    'attorney',
    'agent',
    'courier',
    'process_server',
    'judge',
    'clerk',
    'other',
    'lienholder',
    'hoa',
    'buyer',
    'seller',
    'joint_venture_partner',
    'taxing_authority',
    'foreclosure_trustee',
    'bankruptcy_trustee',
    'receiver',
    'realtor',
    'escrow_agent',
    'insurer',
    'contractor',
    'utility_provider'
);


--
-- Name: review_decision; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.review_decision AS ENUM (
    'approved',
    'rejected'
);


--
-- Name: case_legal_action_frozen_guard(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.case_legal_action_frozen_guard() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
DECLARE
    v_status text;
    v_tenant uuid;
    v_case uuid;
    v_action uuid;
BEGIN
    -- tenant_id is always expected
    v_tenant := COALESCE(NEW.tenant_id, OLD.tenant_id);

    -- case_id may not exist on some tables
    BEGIN
        v_case := COALESCE(NEW.case_id, OLD.case_id);
    EXCEPTION WHEN undefined_column THEN
        v_case := NULL;
    END;

    -- legal_action_id may not exist on some tables
    BEGIN
        v_action := COALESCE(NEW.legal_action_id, OLD.legal_action_id);
    EXCEPTION WHEN undefined_column THEN
        v_action := NULL;
    END;

    IF v_action IS NULL AND v_case IS NULL THEN
        RETURN COALESCE(NEW, OLD);
    END IF;

    IF v_action IS NULL THEN
        SELECT status
        INTO v_status
        FROM legal_actions
        WHERE tenant_id = v_tenant
        AND case_id = v_case
        AND status = 'prepared_for_filing'
        LIMIT 1;
    ELSE
        SELECT status
        INTO v_status
        FROM legal_actions
        WHERE tenant_id = v_tenant
        AND id = v_action
        LIMIT 1;
    END IF;

    IF v_status = 'prepared_for_filing' THEN
        RAISE EXCEPTION 'CASE_FROZEN_PREPARED_FOR_FILING'
        USING ERRCODE = '45000';
    END IF;

    RETURN COALESCE(NEW, OLD);
END;
$$;


--
-- Name: compute_bundle_hash(text, jsonb, jsonb, jsonb); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.compute_bundle_hash(p_intent_hash text, p_structured jsonb, p_exhibit jsonb, p_versions jsonb) RETURNS text
    LANGUAGE sql STABLE
    AS $$
    WITH artifacts_src AS (
        SELECT
            CASE
                -- Mirror BundleHashService::canonicalizeArtifacts() "accept either {artifacts:[...]} or raw [...]"
                WHEN jsonb_typeof(p_structured) = 'object'
                     AND (p_structured ? 'artifacts')
                     AND jsonb_typeof(p_structured->'artifacts') = 'array'
                THEN p_structured->'artifacts'
                ELSE p_structured
            END AS artifacts_json
    ),
    payload AS (
        SELECT jsonb_build_object(
            -- BundleHashService::SCHEMA_VERSION = 1
            'schema_version', 1,
            'intent_hash', COALESCE(p_intent_hash, ''),
            'artifacts', COALESCE(a.artifacts_json, '[]'::jsonb),
            'exhibits', COALESCE(p_exhibit, '[]'::jsonb),
            'version_hashes', COALESCE(p_versions, '[]'::jsonb)
        ) AS j
        FROM artifacts_src a
    )
    SELECT encode(
        digest(
            (SELECT j::text FROM payload)::bytea,
            'sha256'
        ),
        'hex'
    );
$$;


--
-- Name: compute_bundle_hash(text, jsonb, jsonb, jsonb, jsonb, bigint); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.compute_bundle_hash(intent_hash text, structured jsonb, exhibit_linkage jsonb, exhibit_manifest jsonb, version_hashes jsonb, event_cursor bigint) RETURNS text
    LANGUAGE sql IMMUTABLE
    AS $$
SELECT encode(
    digest(
        intent_hash
        || encode(digest(structured::text, 'sha256'), 'hex')
        || encode(digest(exhibit_linkage::text, 'sha256'), 'hex')
        || encode(digest(exhibit_manifest::text, 'sha256'), 'hex')
        || encode(digest(version_hashes::text, 'sha256'), 'hex')
        || event_cursor::text,
        'sha256'
    ),
    'hex'
);
$$;


--
-- Name: compute_bundle_hash(text, text, jsonb, jsonb, jsonb, jsonb, bigint); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.compute_bundle_hash(previous_bundle_hash text, intent_hash text, structured jsonb, exhibit_linkage jsonb, exhibit_manifest jsonb, version_hashes jsonb, event_cursor bigint) RETURNS text
    LANGUAGE sql IMMUTABLE
    AS $$
SELECT encode(
    digest(
        coalesce(previous_bundle_hash,'')
        || intent_hash
        || encode(digest(structured::text, 'sha256'), 'hex')
        || encode(digest(exhibit_linkage::text, 'sha256'), 'hex')
        || encode(digest(exhibit_manifest::text, 'sha256'), 'hex')
        || encode(digest(version_hashes::text, 'sha256'), 'hex')
        || event_cursor::text,
        'sha256'
    ),
    'hex'
);
$$;


--
-- Name: compute_exhibit_linkage_c14n_json(uuid, uuid, uuid); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.compute_exhibit_linkage_c14n_json(p_tenant_id uuid, p_case_id uuid, p_legal_action_id uuid) RETURNS jsonb
    LANGUAGE sql STABLE
    AS $$
    WITH ordered_links AS (
        SELECT
            jsonb_build_object(
                'title', title,
                'required', required,
                'sort_order', sort_order,
                'exhibit_key', exhibit_key,
                'content_hash', content_hash,
                'artifact_type', artifact_type,
                'exhibit_label', exhibit_label,
                'source_version_id', source_version_id,
                'source_case_document_id', source_case_document_id
            ) AS obj
        FROM artifact_exhibit_links
        WHERE tenant_id = p_tenant_id
          AND case_id = p_case_id
          AND legal_action_id = p_legal_action_id
        ORDER BY sort_order, exhibit_key
    )
    SELECT COALESCE(jsonb_agg(obj), '[]'::jsonb)
    FROM ordered_links;
$$;


--
-- Name: document_exports_reject_mutation(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.document_exports_reject_mutation() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
    RAISE EXCEPTION
        'document_exports rows are immutable'
        USING ERRCODE = 'check_violation';
END;
$$;


--
-- Name: enforce_case_document_post_submit_immutability(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.enforce_case_document_post_submit_immutability() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
    IF OLD.status = 'submitted' THEN
        IF NEW.status IS DISTINCT FROM OLD.status THEN
            RAISE EXCEPTION
                'case_document % is submitted and immutable (status)',
                OLD.id;
        END IF;

        IF NEW.current_version_id IS DISTINCT FROM OLD.current_version_id THEN
            RAISE EXCEPTION
                'case_document % is submitted and immutable (current_version_id)',
                OLD.id;
        END IF;
    END IF;

    RETURN NEW;
END;
$$;


--
-- Name: enforce_court_jurisdiction_unit_type(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.enforce_court_jurisdiction_unit_type() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
DECLARE
    ju_type text;
BEGIN
    IF NEW.jurisdiction_unit_id IS NULL THEN
        RAISE EXCEPTION 'COURT_JURISDICTION_UNIT_REQUIRED';
    END IF;

    SELECT unit_type
    INTO ju_type
    FROM jurisdiction_units
    WHERE id = NEW.jurisdiction_unit_id;

    IF ju_type IS NULL THEN
        RAISE EXCEPTION 'JURISDICTION_UNIT_NOT_FOUND';
    END IF;

    CASE NEW.court_type_code
        WHEN 'GENERAL_TRIAL' THEN
            IF ju_type <> 'county' THEN
                RAISE EXCEPTION
                    'GENERAL_TRIAL courts must reference county units';
            END IF;

        WHEN 'LIMITED_TRIAL' THEN
            IF ju_type <> 'justice_precinct' THEN
                RAISE EXCEPTION
                    'LIMITED_TRIAL courts must reference justice_precinct units';
            END IF;

        WHEN 'APPELLATE' THEN
            IF ju_type <> 'state' THEN
                RAISE EXCEPTION
                    'APPELLATE courts must reference state units';
            END IF;

        WHEN 'SUPREME' THEN
            IF ju_type <> 'state' THEN
                RAISE EXCEPTION
                    'SUPREME courts must reference state units';
            END IF;

        ELSE
            RAISE EXCEPTION
                'Unsupported court_type_code: %',
                NEW.court_type_code;
    END CASE;

    RETURN NEW;
END;
$$;


--
-- Name: enforce_demand_delivery_proof(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.enforce_demand_delivery_proof() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
DECLARE
    proof_type text;
BEGIN
    -- Only enforce for terminal service states
    IF NEW.delivery_status IN ('delivered', 'deemed_received') THEN

        IF NEW.proof_case_document_id IS NULL THEN
            RAISE EXCEPTION
                'Proof document required for delivery_status=%',
                NEW.delivery_status;
        END IF;

        SELECT document_type_key
        INTO proof_type
        FROM case_documents
        WHERE id = NEW.proof_case_document_id;

        IF proof_type IS NULL THEN
            RAISE EXCEPTION
                'Proof document % not found',
                NEW.proof_case_document_id;
        END IF;

        IF proof_type NOT IN (
            'az_demand_service_proof',
            'az_personal_service_affidavit',
            'az_certified_mail_deemed_receipt',
            'az_certified_mail_return_receipt'
        ) THEN
            RAISE EXCEPTION
                'Invalid proof document type: %',
                proof_type;
        END IF;

    END IF;

    RETURN NEW;
END;
$$;


--
-- Name: enforce_filing_packet_dependencies(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.enforce_filing_packet_dependencies() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
DECLARE
    doc_count int;
    arbitration_present boolean;
BEGIN

    IF NEW.document_type <> 'filing_packet' THEN
        RETURN NEW;
    END IF;

    SELECT count(*) INTO doc_count
    FROM filing_documents
    WHERE tenant_id = NEW.tenant_id
      AND bundle_id = NEW.bundle_id
      AND document_type IN ('complaint','summons','exhibit_packet');

    IF doc_count <> 3 THEN
        RAISE EXCEPTION 'FILING_PACKET_DEPENDENCIES_MISSING';
    END IF;

    SELECT EXISTS (
        SELECT 1
        FROM filing_documents
        WHERE tenant_id = NEW.tenant_id
          AND bundle_id = NEW.bundle_id
          AND document_type = 'certificate_compulsory_arbitration'
    ) INTO arbitration_present;

    IF arbitration_present AND NOT EXISTS (
        SELECT 1
        FROM filing_documents
        WHERE tenant_id = NEW.tenant_id
          AND bundle_id = NEW.bundle_id
          AND document_type = 'certificate_compulsory_arbitration'
    ) THEN
        RAISE EXCEPTION 'ARBITRATION_CERTIFICATE_REQUIRED_FOR_SUPERIOR_COURT_PACKET';
    END IF;

    RETURN NEW;
END;
$$;


--
-- Name: enforce_final_document_immutability(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.enforce_final_document_immutability() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
    -- Only care about UPDATEs
    IF TG_OP <> 'UPDATE' THEN
        RETURN NEW;
    END IF;

    -- If the version is final, block changes to storage_uri or content_hash
    IF OLD.is_final = true THEN
        IF NEW.storage_uri IS DISTINCT FROM OLD.storage_uri THEN
            RAISE EXCEPTION
                'storage_uri is immutable for finalized document versions'
                USING ERRCODE = 'integrity_constraint_violation';
        END IF;

        IF NEW.content_hash IS DISTINCT FROM OLD.content_hash THEN
            RAISE EXCEPTION
                'content_hash is immutable for finalized document versions'
                USING ERRCODE = 'integrity_constraint_violation';
        END IF;
    END IF;

    RETURN NEW;
END;
$$;


--
-- Name: enforce_jurisdiction_unit_parent_types(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.enforce_jurisdiction_unit_parent_types() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
DECLARE
  parent_type text;
BEGIN
  -- If no parent, only some types are allowed to be root.
  IF NEW.parent_id IS NULL THEN
    -- precinct must always have a parent
    IF NEW.unit_type = 'justice_precinct' THEN
      RAISE EXCEPTION 'PARENT_REQUIRED: justice_precinct must have county parent';
    END IF;
    RETURN NEW;
  END IF;

  SELECT unit_type INTO parent_type
  FROM jurisdiction_units
  WHERE id = NEW.parent_id;

  IF parent_type IS NULL THEN
    RAISE EXCEPTION 'PARENT_NOT_FOUND';
  END IF;

  IF NEW.unit_type = 'justice_precinct' AND parent_type <> 'county' THEN
    RAISE EXCEPTION 'PARENT_TYPE_MISMATCH: justice_precinct parent must be county';
  END IF;

  -- Optional: if you have unit_type='county' and want it under state:
  -- IF NEW.unit_type = 'county' AND parent_type <> 'state' THEN
  --   RAISE EXCEPTION 'PARENT_TYPE_MISMATCH: county parent must be state';
  -- END IF;

  RETURN NEW;
END;
$$;


--
-- Name: filing_bundles_immutable_guard(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.filing_bundles_immutable_guard() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
    -- Prevent delete of committed bundles
    IF TG_OP = 'DELETE' AND OLD.freeze_level = 'committed' THEN
        RAISE EXCEPTION 'COMMITTED_FILING_BUNDLE_IMMUTABLE'
            USING ERRCODE = '45000';
    END IF;

    -- Prevent update of committed bundles
    IF TG_OP = 'UPDATE' AND OLD.freeze_level = 'committed' THEN
        RAISE EXCEPTION 'COMMITTED_FILING_BUNDLE_IMMUTABLE'
            USING ERRCODE = '45000';
    END IF;

    RETURN COALESCE(NEW, OLD);
END;
$$;


--
-- Name: freeze_guard(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.freeze_guard() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
DECLARE
    v_row jsonb;
    v_tenant uuid;
    v_case uuid;
    v_action uuid;
    v_state text;
BEGIN
    v_row := to_jsonb(COALESCE(NEW, OLD));

    v_tenant := (v_row->>'tenant_id')::uuid;
    v_case   := (v_row->>'case_id')::uuid;
    v_action := (v_row->>'legal_action_id')::uuid;

    IF v_tenant IS NULL OR v_case IS NULL THEN
        RETURN COALESCE(NEW, OLD);
    END IF;

    v_state := public.get_case_freeze_state(v_tenant, v_case, v_action);

    IF v_state <> 'mutable' THEN
        RAISE EXCEPTION 'CASE_FROZEN_%', upper(v_state)
        USING ERRCODE = '45000';
    END IF;

    RETURN COALESCE(NEW, OLD);
END;
$$;


--
-- Name: get_case_freeze_state(uuid, uuid, uuid); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.get_case_freeze_state(p_tenant uuid, p_case uuid, p_action uuid DEFAULT NULL::uuid) RETURNS text
    LANGUAGE plpgsql
    AS $$
DECLARE
    v_status text;
BEGIN
    IF p_action IS NOT NULL THEN
        SELECT status
        INTO v_status
        FROM legal_actions
        WHERE tenant_id = p_tenant
        AND id = p_action
        LIMIT 1;

        IF v_status IS NOT NULL THEN
            RETURN v_status;
        END IF;
    END IF;

    SELECT status
    INTO v_status
    FROM legal_actions
    WHERE tenant_id = p_tenant
      AND case_id = p_case
      AND status IN (
          'prepared_for_filing',
          'filed',
          'judgment_entered',
          'writ_issued'
      )
    ORDER BY CASE status
        WHEN 'writ_issued' THEN 400
        WHEN 'judgment_entered' THEN 300
        WHEN 'filed' THEN 200
        WHEN 'prepared_for_filing' THEN 100
    END DESC
    LIMIT 1;

    RETURN COALESCE(v_status,'mutable');
END;
$$;


--
-- Name: guard_case_mutation(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.guard_case_mutation() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
DECLARE
    v_tenant uuid;
    v_case uuid;
    v_state text;
BEGIN
    v_tenant := COALESCE(NEW.tenant_id, OLD.tenant_id);
    v_case   := COALESCE(NEW.case_id, OLD.case_id);

    v_state := public.get_case_freeze_state(v_tenant, v_case, NULL);

    IF v_state <> 'mutable' THEN
        RAISE EXCEPTION 'CASE_FROZEN_%', upper(v_state)
        USING ERRCODE = '45000';
    END IF;

    RETURN COALESCE(NEW, OLD);
END;
$$;


--
-- Name: guard_legal_action_mutation(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.guard_legal_action_mutation() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
DECLARE
    v_tenant_id       uuid;
    v_case_id         uuid;
    v_legal_action_id uuid;
    v_state           text;
BEGIN
    v_tenant_id := COALESCE(
        NEW.tenant_id,
        OLD.tenant_id
    );

    v_case_id := COALESCE(
        NEW.case_id,
        OLD.case_id
    );

    /*
     * This trigger is attached directly to legal_actions.
     * The legal action's primary key is "id".
     */
    v_legal_action_id := COALESCE(
        NEW.id,
        OLD.id
    );

    IF v_tenant_id IS NULL OR v_case_id IS NULL THEN
        RAISE EXCEPTION
            'GUARD_LEGAL_ACTION_MUTATION_REQUIRES_TENANT_AND_CASE'
            USING ERRCODE = '45000';
    END IF;

    v_state := public.get_case_freeze_state(
        v_tenant_id,
        v_case_id,
        v_legal_action_id
    );

    IF v_state <> 'mutable' THEN
        RAISE EXCEPTION
            'CASE_FROZEN_%',
            upper(v_state)
            USING ERRCODE = '45000';
    END IF;

    RETURN COALESCE(NEW, OLD);
END;
$$;


--
-- Name: is_case_frozen(uuid, uuid, uuid); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.is_case_frozen(p_tenant_id uuid, p_case_id uuid, p_legal_action_id uuid DEFAULT NULL::uuid) RETURNS boolean
    LANGUAGE sql
    AS $_$
    SELECT public.get_case_freeze_state($1, $2, $3) <> 'mutable';
$_$;


--
-- Name: is_freeze_exempt_table(text); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.is_freeze_exempt_table(p_table_name text) RETURNS boolean
    LANGUAGE sql
    AS $$
    SELECT p_table_name = ANY (ARRAY[
        'case_document_versions',
        'audit_events',
        'artifact_exports',
        'artifact_render_jobs'
    ]);
$$;


--
-- Name: prevent_audit_mutation(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.prevent_audit_mutation() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
    RAISE EXCEPTION 'audit_events are immutable';
END;
$$;


--
-- Name: prevent_finalized_version_reassignment(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.prevent_finalized_version_reassignment() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
    IF OLD.status = 'final' AND NEW.current_version_id <> OLD.current_version_id THEN
        RAISE EXCEPTION 'Cannot change current_version_id of finalized case_document';
    END IF;
    RETURN NEW;
END;
$$;


--
-- Name: prevent_versions_after_case_document_submit(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.prevent_versions_after_case_document_submit() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
DECLARE
    cd_status text;
BEGIN
    SELECT status
    INTO cd_status
    FROM case_documents
    WHERE current_version_id = NEW.id
    LIMIT 1;

    IF cd_status IS NOT NULL AND cd_status <> 'draft' THEN
        RAISE EXCEPTION
            'Cannot add versions when case_document status is %',
            cd_status;
    END IF;

    RETURN NEW;
END;
$$;


--
-- Name: reject_mutation(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.reject_mutation() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
    RAISE EXCEPTION
        'Mutation forbidden: table "%" is immutable',
        TG_TABLE_NAME
        USING ERRCODE = 'check_violation';
END;
$$;


--
-- Name: reopen_legal_action_for_amendment(uuid, uuid, uuid, text); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.reopen_legal_action_for_amendment(p_tenant_id uuid, p_legal_action_id uuid, p_actor_id uuid, p_reason text) RETURNS void
    LANGUAGE plpgsql
    AS $$
BEGIN
    UPDATE legal_actions
    SET status = 'open'
    WHERE tenant_id = p_tenant_id
      AND id = p_legal_action_id
      AND status IN ('prepared_for_filing', 'filed');

    INSERT INTO case_events (
        id,
        tenant_id,
        case_id,
        event_type,
        occurred_at,
        actor_id,
        payload,
        created_at
    )
    SELECT
        uuid_generate_v4(),
        la.tenant_id,
        la.case_id,
        'legal_action_reopened',
        now(),
        p_actor_id,
        jsonb_build_object(
            'legal_action_id', la.id,
            'reason', p_reason
        ),
        now()
    FROM legal_actions la
    WHERE la.tenant_id = p_tenant_id
      AND la.id = p_legal_action_id;
END;
$$;


--
-- Name: verify_case_event_hash(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.verify_case_event_hash() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
DECLARE
    computed_hash text;
    canonical_payload text;
BEGIN

    /*
    ------------------------------------------------------------
    Canonicalize payload
    Ensures deterministic JSON ordering
    ------------------------------------------------------------
    */

    canonical_payload :=
        COALESCE(
            jsonb_strip_nulls(NEW.payload)::text,
            ''
        );

    /*
    ------------------------------------------------------------
    Compute deterministic hash
    ------------------------------------------------------------
    */

    computed_hash :=
        encode(
            digest(
                COALESCE(NEW.previous_event_hash,'') ||
                NEW.case_id::text ||
                NEW.case_sequence::text ||
                NEW.event_type ||
                canonical_payload,
                'sha256'
            ),
            'hex'
        );

    /*
    ------------------------------------------------------------
    Validate hash
    ------------------------------------------------------------
    */

    IF NEW.event_hash IS DISTINCT FROM computed_hash THEN
        RAISE EXCEPTION
            'CASE_EVENT_HASH_MISMATCH';
    END IF;

    RETURN NEW;
END;
$$;


SET default_tablespace = '';

SET default_table_access_method = heap;

--
-- Name: action_document_expectations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.action_document_expectations (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    tenant_id uuid,
    state_code text NOT NULL,
    legal_action_key text NOT NULL,
    requested_action text NOT NULL,
    phase_key text NOT NULL,
    document_type_key text NOT NULL,
    required boolean DEFAULT true NOT NULL,
    source text NOT NULL,
    must_be_final boolean DEFAULT true NOT NULL,
    blocks_completion boolean DEFAULT true NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT action_document_expectations_source_check CHECK ((source = ANY (ARRAY['system'::text, 'user'::text, 'court'::text, 'sheriff'::text]))),
    CONSTRAINT chk_document_type_key_known CHECK ((document_type_key = ANY (ARRAY['demand_for_possession'::text, 'trustee_deed'::text, 'trustee_deed_recording'::text, 'forcible_detainer_complaint'::text, 'summons'::text, 'exhibits_packet'::text, 'affidavit_of_service'::text, 'judgment'::text, 'writ_of_restitution'::text, 'writ_return'::text])))
);


--
-- Name: actor_identities; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.actor_identities (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    tenant_id uuid NOT NULL,
    actor_id uuid NOT NULL,
    effective_from timestamp with time zone NOT NULL,
    effective_to timestamp with time zone,
    first_name text,
    middle_name text,
    last_name text,
    legal_name text,
    doing_business_as text,
    email text,
    phone text,
    address_text text,
    source text NOT NULL,
    created_by uuid,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT actor_identities_check CHECK ((((first_name IS NOT NULL) AND (last_name IS NOT NULL)) OR (legal_name IS NOT NULL)))
);


--
-- Name: actors; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.actors (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    tenant_id uuid NOT NULL,
    actor_type public.actor_type NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: api_idempotency_keys; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.api_idempotency_keys (
    id bigint NOT NULL,
    tenant_id uuid NOT NULL,
    idempotency_key text NOT NULL,
    request_hash text NOT NULL,
    status text NOT NULL,
    response_http_status integer,
    response_body jsonb,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    completed_at timestamp with time zone,
    CONSTRAINT api_idempotency_keys_status_check CHECK ((status = ANY (ARRAY['started'::text, 'completed'::text])))
);


--
-- Name: api_idempotency_keys_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.api_idempotency_keys_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: api_idempotency_keys_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.api_idempotency_keys_id_seq OWNED BY public.api_idempotency_keys.id;


--
-- Name: artifact_exhibit_links; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.artifact_exhibit_links (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    tenant_id uuid NOT NULL,
    case_id uuid NOT NULL,
    legal_action_id uuid NOT NULL,
    action_type text NOT NULL,
    artifact_type text NOT NULL,
    intent_hash character(64) NOT NULL,
    exhibit_key text NOT NULL,
    exhibit_label text NOT NULL,
    title text,
    source_case_document_id uuid NOT NULL,
    source_version_id uuid NOT NULL,
    content_hash character(64) NOT NULL,
    required boolean DEFAULT false NOT NULL,
    sort_order integer NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: assignments; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.assignments (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    tenant_id uuid NOT NULL,
    case_id uuid NOT NULL,
    service_execution_id uuid,
    assignee_actor_id uuid NOT NULL,
    assignment_role text NOT NULL,
    assigned_at timestamp with time zone NOT NULL,
    accepted_at timestamp with time zone,
    declined_at timestamp with time zone,
    completed_at timestamp with time zone,
    status text NOT NULL,
    created_by uuid
);


--
-- Name: attorney_filing_profiles; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.attorney_filing_profiles (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    tenant_id uuid NOT NULL,
    actor_id uuid NOT NULL,
    display_name text NOT NULL,
    bar_number text NOT NULL,
    firm_name text,
    address_line1 text NOT NULL,
    address_line2 text,
    city text NOT NULL,
    state text NOT NULL,
    postal_code text NOT NULL,
    phone text,
    email text,
    effective_from timestamp with time zone DEFAULT now() NOT NULL,
    effective_to timestamp with time zone,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT attorney_filing_profiles_bar_number_check CHECK ((btrim(bar_number) <> ''::text)),
    CONSTRAINT attorney_filing_profiles_display_name_check CHECK ((btrim(display_name) <> ''::text)),
    CONSTRAINT attorney_filing_profiles_effective_range_check CHECK (((effective_to IS NULL) OR (effective_to > effective_from)))
);


--
-- Name: TABLE attorney_filing_profiles; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON TABLE public.attorney_filing_profiles IS 'Temporal attorney-specific filing identity and professional credentials associated with an actor.';


--
-- Name: COLUMN attorney_filing_profiles.actor_id; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.attorney_filing_profiles.actor_id IS 'Actor representing the attorney whose filing credentials are described by this profile.';


--
-- Name: COLUMN attorney_filing_profiles.bar_number; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.attorney_filing_profiles.bar_number IS 'Attorney bar number used on court filings.';


--
-- Name: COLUMN attorney_filing_profiles.effective_from; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.attorney_filing_profiles.effective_from IS 'Timestamp at which this professional filing profile becomes effective.';


--
-- Name: COLUMN attorney_filing_profiles.effective_to; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.attorney_filing_profiles.effective_to IS 'Timestamp at which this professional filing profile ceases to be effective; NULL means currently active.';


--
-- Name: audit_events; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.audit_events (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    tenant_id uuid NOT NULL,
    event_at timestamp with time zone NOT NULL,
    actor_user_id uuid,
    entity_type text NOT NULL,
    entity_id uuid NOT NULL,
    operation text NOT NULL,
    summary text,
    diff_payload jsonb,
    retention_expires_at timestamp without time zone,
    legal_hold boolean DEFAULT false NOT NULL,
    legal_hold_reason text,
    actor_id uuid,
    CONSTRAINT audit_events_hold_guard CHECK (((legal_hold = false) OR (retention_expires_at IS NULL)))
);


--
-- Name: auth_access_token_revocations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.auth_access_token_revocations (
    jti text NOT NULL,
    revoked_at timestamp with time zone DEFAULT now() NOT NULL,
    reason text,
    tenant_id uuid NOT NULL
);


--
-- Name: case_capability_grants; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.case_capability_grants (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    tenant_id uuid NOT NULL,
    case_id uuid NOT NULL,
    grantee_actor_id uuid NOT NULL,
    capability text NOT NULL,
    starts_at timestamp with time zone NOT NULL,
    ends_at timestamp with time zone,
    granted_by uuid,
    granted_at timestamp with time zone DEFAULT now() NOT NULL,
    revoked_at timestamp with time zone,
    reason text
);


--
-- Name: case_deadlines; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.case_deadlines (
    id uuid NOT NULL,
    tenant_id uuid NOT NULL,
    case_id uuid NOT NULL,
    deadline_type text NOT NULL,
    due_at timestamp with time zone NOT NULL,
    status text NOT NULL,
    rule_source jsonb,
    derived_from jsonb NOT NULL,
    calculation jsonb NOT NULL,
    resolved_by jsonb,
    superseded_by uuid,
    created_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: case_documents; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.case_documents (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    tenant_id uuid NOT NULL,
    case_id uuid NOT NULL,
    document_type_key text NOT NULL,
    title text,
    status text DEFAULT 'draft'::text NOT NULL,
    is_evidence boolean DEFAULT false NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    created_by uuid,
    current_version_id uuid,
    metadata jsonb,
    finalized_at timestamp with time zone,
    final_version_id uuid,
    final_version_number integer,
    source_action_request_id uuid,
    CONSTRAINT case_documents_finalized_at_check CHECK ((((status <> 'final'::text) AND (finalized_at IS NULL)) OR ((status = 'final'::text) AND (finalized_at IS NOT NULL)))),
    CONSTRAINT chk_submitted_requires_version CHECK (((status <> 'submitted'::text) OR (current_version_id IS NOT NULL)))
);


--
-- Name: case_event_sequence; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.case_event_sequence
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: case_events; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.case_events (
    id uuid NOT NULL,
    tenant_id uuid NOT NULL,
    case_id uuid NOT NULL,
    event_type text NOT NULL,
    occurred_at timestamp with time zone NOT NULL,
    actor_id uuid,
    payload jsonb,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    executed_at timestamp with time zone,
    idempotency_key text,
    case_sequence bigint NOT NULL,
    causation_id uuid,
    correlation_id uuid,
    previous_event_hash text,
    event_hash text,
    CONSTRAINT chk_foreclosure_purchaser_entity CHECK (((event_type <> 'foreclosure_sale'::text) OR (payload ? 'purchaser_entity_id'::text))),
    CONSTRAINT chk_trustee_deed_grantee CHECK (((event_type <> 'trustee_deed_recorded'::text) OR (payload ? 'grantee_entity_id'::text)))
);


--
-- Name: case_ingress_submissions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.case_ingress_submissions (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    tenant_id uuid NOT NULL,
    case_id uuid NOT NULL,
    presented_court_id uuid,
    presented_case_number text,
    submission_type text NOT NULL,
    submitter_name text,
    submitter_contact text,
    status text DEFAULT 'received'::text NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    metadata jsonb
);


--
-- Name: case_lifecycle_events; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.case_lifecycle_events (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    tenant_id uuid NOT NULL,
    case_id uuid NOT NULL,
    from_status text,
    to_status text NOT NULL,
    reason text,
    changed_by uuid,
    changed_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: case_occupant_action_targets; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.case_occupant_action_targets (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    tenant_id uuid NOT NULL,
    case_id uuid NOT NULL,
    occupant_id uuid NOT NULL,
    action_request_id uuid NOT NULL,
    phase_key text NOT NULL,
    action_type text NOT NULL,
    required boolean DEFAULT true NOT NULL,
    status text DEFAULT 'pending'::text NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT chk_target_status CHECK ((status = ANY (ARRAY['pending'::text, 'served'::text, 'failed'::text, 'waived'::text])))
);


--
-- Name: case_parties; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.case_parties (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    tenant_id uuid NOT NULL,
    case_id uuid NOT NULL,
    actor_id uuid NOT NULL,
    party_role public.party_role NOT NULL,
    starts_at timestamp with time zone NOT NULL,
    ends_at timestamp with time zone,
    notes text,
    caption_order integer DEFAULT 100
);


--
-- Name: case_party_contacts; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.case_party_contacts (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    tenant_id uuid NOT NULL,
    case_id uuid NOT NULL,
    actor_id uuid NOT NULL,
    first_name text NOT NULL,
    middle_name text,
    last_name text NOT NULL,
    email text,
    phone text,
    address_line1 text NOT NULL,
    address_line2 text,
    city text NOT NULL,
    state text NOT NULL,
    postal_code text NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: case_prerequisite_compliance; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.case_prerequisite_compliance (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    tenant_id uuid NOT NULL,
    case_id uuid NOT NULL,
    phase_key text NOT NULL,
    action_type text NOT NULL,
    occurred_at timestamp with time zone NOT NULL,
    delivery_method text,
    delivery_recipient_name text,
    delivery_address text,
    delivery_tracking_number text,
    delivery_server_name text,
    notes text,
    attested_by_actor_id uuid NOT NULL,
    attested_at timestamp with time zone DEFAULT now() NOT NULL,
    attestation_text text NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: case_prerequisite_compliance_evidence; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.case_prerequisite_compliance_evidence (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    tenant_id uuid NOT NULL,
    compliance_id uuid NOT NULL,
    document_id uuid NOT NULL,
    document_version_id uuid,
    created_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: case_procedural_deadlines; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.case_procedural_deadlines (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    tenant_id uuid NOT NULL,
    case_id uuid NOT NULL,
    legal_action_request_id uuid,
    phase_key text NOT NULL,
    obligation_key text NOT NULL,
    display_name text NOT NULL,
    due_at timestamp with time zone NOT NULL,
    required boolean DEFAULT true NOT NULL,
    blocks_phase_key text,
    status text DEFAULT 'pending'::text NOT NULL,
    completed_at timestamp with time zone,
    completed_by uuid,
    payload jsonb,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT chk_cpd_status CHECK ((status = ANY (ARRAY['pending'::text, 'completed'::text, 'waived'::text, 'not_applicable'::text])))
);


--
-- Name: case_procedure_snapshots; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.case_procedure_snapshots (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    case_id uuid NOT NULL,
    procedure_name text NOT NULL,
    snapshot_sequence bigint NOT NULL,
    procedure_state jsonb NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: case_properties; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.case_properties (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    tenant_id uuid NOT NULL,
    case_id uuid NOT NULL,
    property_id uuid NOT NULL,
    starts_at timestamp with time zone NOT NULL,
    ends_at timestamp with time zone,
    reason text
);


--
-- Name: case_venue_assignments; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.case_venue_assignments (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    case_id uuid NOT NULL,
    legal_action_id uuid NOT NULL,
    state_code text NOT NULL,
    court_id uuid NOT NULL,
    precinct_id uuid,
    stage text NOT NULL,
    assigned_by uuid NOT NULL,
    assigned_at timestamp with time zone DEFAULT now() NOT NULL,
    jurisdiction_unit_id uuid NOT NULL
);


--
-- Name: case_venue_overrides; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.case_venue_overrides (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    tenant_id uuid NOT NULL,
    case_id uuid NOT NULL,
    jurisdiction_unit_id uuid NOT NULL,
    reason text,
    created_by uuid,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    superseded_at timestamp with time zone
);


--
-- Name: cases; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.cases (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    tenant_id uuid NOT NULL,
    client_account_id uuid,
    case_number text,
    status text NOT NULL,
    opened_at timestamp with time zone NOT NULL,
    closed_at timestamp with time zone,
    created_by uuid,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    court_id uuid,
    case_type text DEFAULT 'court'::text NOT NULL,
    filing_committed_at timestamp with time zone,
    case_event_cursor bigint DEFAULT 0 NOT NULL,
    CONSTRAINT chk_cases_case_type CHECK ((case_type = ANY (ARRAY['court'::text, 'lease'::text, 'transaction'::text, 'eviction'::text, 'foreclosure'::text, 'quiet_title'::text, 'service_request'::text])))
);


--
-- Name: counties; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.counties (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    state_id uuid NOT NULL,
    name text NOT NULL,
    fips_code text,
    created_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: court_filing_locations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.court_filing_locations (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    court_id uuid NOT NULL,
    location_name text NOT NULL,
    address_line1 text NOT NULL,
    address_line2 text,
    city text NOT NULL,
    state text NOT NULL,
    postal_code text NOT NULL,
    clerk_name text,
    accepts_filings boolean DEFAULT true NOT NULL,
    is_primary boolean DEFAULT false NOT NULL,
    sort_order integer DEFAULT 0 NOT NULL,
    effective_from date DEFAULT CURRENT_DATE NOT NULL,
    effective_to date,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT court_filing_locations_effective_range_check CHECK (((effective_to IS NULL) OR (effective_to >= effective_from))),
    CONSTRAINT court_filing_locations_sort_order_check CHECK ((sort_order >= 0))
);


--
-- Name: TABLE court_filing_locations; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON TABLE public.court_filing_locations IS 'Temporal physical filing locations and clerk information for courts. Used by filing intent and artifact generation.';


--
-- Name: COLUMN court_filing_locations.court_id; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.court_filing_locations.court_id IS 'Court to which this filing location belongs. Venue resolution continues to resolve the court, not the filing location.';


--
-- Name: COLUMN court_filing_locations.accepts_filings; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.court_filing_locations.accepts_filings IS 'Whether this location is currently eligible to receive filings.';


--
-- Name: COLUMN court_filing_locations.is_primary; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.court_filing_locations.is_primary IS 'Identifies the preferred/default filing location when a specific location has not otherwise been selected.';


--
-- Name: COLUMN court_filing_locations.effective_from; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.court_filing_locations.effective_from IS 'First date on which this filing-location configuration is effective.';


--
-- Name: COLUMN court_filing_locations.effective_to; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.court_filing_locations.effective_to IS 'Last date on which this configuration is effective; NULL means currently active.';


--
-- Name: court_precincts; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.court_precincts (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    county_id uuid NOT NULL,
    court_id uuid NOT NULL,
    name text NOT NULL,
    code text NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: court_tier_jurisdiction_requirements; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.court_tier_jurisdiction_requirements (
    court_type_code text NOT NULL,
    required_unit_type text NOT NULL,
    requires_parent boolean DEFAULT false NOT NULL,
    parent_unit_type text,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    resolution_strategy text DEFAULT 'spatial_only'::text NOT NULL,
    CONSTRAINT court_tier_parent_logic_check CHECK ((((requires_parent = false) AND (parent_unit_type IS NULL)) OR ((requires_parent = true) AND (parent_unit_type IS NOT NULL)))),
    CONSTRAINT court_tier_resolution_strategy_check CHECK ((resolution_strategy = ANY (ARRAY['spatial_only'::text, 'spatial_then_name'::text, 'name_only'::text])))
);


--
-- Name: courts; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.courts (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    state_id uuid NOT NULL,
    county_id uuid,
    court_type text NOT NULL,
    name text NOT NULL,
    jurisdiction text NOT NULL,
    venue_scope text NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    jurisdiction_unit_id uuid NOT NULL,
    court_type_code text NOT NULL
);


--
-- Name: cron_job_runs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.cron_job_runs (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    job_name text NOT NULL,
    status text NOT NULL,
    started_at timestamp with time zone DEFAULT now() NOT NULL,
    finished_at timestamp with time zone,
    exit_code integer,
    host text,
    pid integer,
    lock_slot integer,
    argv jsonb DEFAULT '[]'::jsonb NOT NULL,
    context jsonb DEFAULT '{}'::jsonb NOT NULL,
    metrics jsonb DEFAULT '{}'::jsonb NOT NULL,
    error_message text,
    CONSTRAINT cron_job_runs_status_check CHECK ((status = ANY (ARRAY['running'::text, 'success'::text, 'failed'::text, 'skipped_lock'::text])))
);


--
-- Name: delivery_methods; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.delivery_methods (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    code text NOT NULL,
    label text NOT NULL,
    description text,
    service_class text NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: demand_deliveries; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.demand_deliveries (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    tenant_id uuid NOT NULL,
    case_id uuid NOT NULL,
    action_request_id uuid NOT NULL,
    method public.demand_delivery_method NOT NULL,
    delivered_at timestamp with time zone,
    proof_document_id uuid,
    created_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: demand_delivery_events; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.demand_delivery_events (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    tenant_id uuid NOT NULL,
    case_id uuid NOT NULL,
    action_request_id uuid NOT NULL,
    delivery_method text NOT NULL,
    delivered_at timestamp with time zone,
    delivered_by uuid,
    address_line1 text NOT NULL,
    address_line2 text,
    city text NOT NULL,
    state text NOT NULL,
    postal_code text NOT NULL,
    proof_document_id uuid,
    notes text,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    tracking_number text,
    proof_case_document_id uuid,
    service_task_id uuid NOT NULL,
    occupant_id uuid,
    delivery_status text DEFAULT 'pending'::text NOT NULL,
    target_id uuid NOT NULL,
    cron_claimed_at timestamp with time zone,
    cron_claimed_by text,
    cron_claim_token uuid,
    CONSTRAINT chk_delivery_requires_target CHECK ((target_id IS NOT NULL)),
    CONSTRAINT chk_delivery_status CHECK ((delivery_status = ANY (ARRAY['pending'::text, 'sent'::text, 'delivered'::text, 'deemed_received'::text, 'failed'::text, 'returned'::text]))),
    CONSTRAINT chk_demand_delivery_method CHECK ((delivery_method = ANY (ARRAY['certified_mail'::text, 'courier'::text, 'personal_service'::text, 'personal_delivery'::text, 'posting'::text])))
);


--
-- Name: document_capability_grants; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.document_capability_grants (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    tenant_id uuid NOT NULL,
    document_id uuid NOT NULL,
    grantee_actor_id uuid NOT NULL,
    capability text NOT NULL,
    starts_at timestamp with time zone NOT NULL,
    ends_at timestamp with time zone,
    granted_by uuid,
    granted_at timestamp with time zone DEFAULT now() NOT NULL,
    revoked_at timestamp with time zone,
    reason text
);


--
-- Name: document_events; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.document_events (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    tenant_id uuid NOT NULL,
    document_id uuid NOT NULL,
    from_status text,
    to_status text NOT NULL,
    reason text,
    changed_by uuid,
    changed_at timestamp with time zone DEFAULT now() NOT NULL,
    metadata jsonb
);


--
-- Name: document_exports; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.document_exports (
    id uuid NOT NULL,
    tenant_id uuid NOT NULL,
    case_document_id uuid NOT NULL,
    issued_at timestamp with time zone NOT NULL,
    issued_by_actor_id uuid NOT NULL,
    issued_by_actor_type text NOT NULL,
    purpose text NOT NULL,
    format text NOT NULL,
    delivery text NOT NULL,
    reason text,
    final_version_id uuid NOT NULL,
    final_version_number integer NOT NULL,
    content_hash text NOT NULL,
    storage_uri text NOT NULL
);


--
-- Name: document_groupings; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.document_groupings (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    tenant_id uuid NOT NULL,
    actor_id uuid,
    name text NOT NULL,
    type text NOT NULL,
    definition jsonb,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT chk_grouping_type CHECK ((type = ANY (ARRAY['static'::text, 'smart'::text])))
);


--
-- Name: document_template_versions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.document_template_versions (
    tenant_id uuid NOT NULL,
    template_key text NOT NULL,
    version text NOT NULL,
    source_type text NOT NULL,
    compiled_template_json jsonb NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: document_types; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.document_types (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    tenant_id uuid,
    type_key text NOT NULL,
    display_name text NOT NULL,
    is_active boolean DEFAULT true NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    created_by uuid
);


--
-- Name: document_versions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.document_versions (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    tenant_id uuid NOT NULL,
    document_id uuid NOT NULL,
    version_number integer NOT NULL,
    is_final boolean DEFAULT false NOT NULL,
    content_hash text NOT NULL,
    storage_uri text NOT NULL,
    page_count integer,
    rendered_at timestamp with time zone,
    rendered_by uuid,
    source text NOT NULL,
    inputs_snapshot jsonb,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    finalized_at timestamp with time zone,
    CONSTRAINT document_versions_finalized_at_check CHECK ((((is_final = false) AND (finalized_at IS NULL)) OR ((is_final = true) AND (finalized_at IS NOT NULL)))),
    CONSTRAINT document_versions_storage_required_if_final CHECK (((is_final = false) OR ((storage_uri IS NOT NULL) AND (content_hash IS NOT NULL))))
);


--
-- Name: documents; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.documents (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    tenant_id uuid NOT NULL,
    case_id uuid NOT NULL,
    legal_action_id uuid,
    document_type text NOT NULL,
    status public.document_status NOT NULL,
    filed_at timestamp with time zone,
    filed_by uuid,
    created_by uuid,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    visibility text DEFAULT 'private'::text NOT NULL,
    canonical_tags text[] DEFAULT '{}'::text[] NOT NULL,
    freeform_tags text[] DEFAULT '{}'::text[] NOT NULL,
    process_service_event_id uuid,
    CONSTRAINT chk_documents_visibility CHECK ((visibility = ANY (ARRAY['private'::text, 'public'::text])))
);


--
-- Name: event_replay_audit; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.event_replay_audit (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    tenant_id uuid NOT NULL,
    case_id uuid NOT NULL,
    replayed_by uuid,
    reason text,
    dry_run boolean DEFAULT false NOT NULL,
    started_at timestamp with time zone DEFAULT now() NOT NULL,
    completed_at timestamp with time zone,
    event_count integer DEFAULT 0 NOT NULL,
    applied_effect_count integer DEFAULT 0 NOT NULL,
    status text DEFAULT 'running'::text NOT NULL,
    error_message text
);


--
-- Name: evidence_access_grants; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.evidence_access_grants (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    tenant_id uuid NOT NULL,
    document_export_id uuid NOT NULL,
    subject text NOT NULL,
    purpose text NOT NULL,
    token_hash text NOT NULL,
    issued_at timestamp with time zone DEFAULT now() NOT NULL,
    issued_by_actor_id uuid NOT NULL,
    issued_by_actor_type text NOT NULL,
    expires_at timestamp with time zone,
    revoked_at timestamp with time zone,
    revoked_by_actor_id uuid,
    revoked_by_actor_type text,
    metadata jsonb,
    CONSTRAINT evidence_access_grants_revocation_consistency CHECK ((((revoked_at IS NULL) AND (revoked_by_actor_id IS NULL) AND (revoked_by_actor_type IS NULL)) OR (revoked_at IS NOT NULL)))
);


--
-- Name: evidence_items; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.evidence_items (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    tenant_id uuid NOT NULL,
    case_id uuid NOT NULL,
    legal_action_id uuid,
    service_execution_id uuid,
    evidence_type text NOT NULL,
    capture_at timestamp with time zone NOT NULL,
    captured_by_actor_id uuid,
    content_hash text NOT NULL,
    storage_uri text NOT NULL,
    status public.evidence_status NOT NULL,
    accepted_by uuid,
    accepted_at timestamp with time zone,
    rejected_by uuid,
    rejected_at timestamp with time zone,
    rejection_reason text,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT evidence_items_check CHECK (((legal_action_id IS NOT NULL) OR (service_execution_id IS NOT NULL)))
);


--
-- Name: exhibit_bundle_workspaces; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.exhibit_bundle_workspaces (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    case_id uuid NOT NULL,
    created_by_actor_id uuid NOT NULL,
    purpose text,
    labeling_scheme text NOT NULL,
    contribution_deadline timestamp with time zone NOT NULL,
    status text NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT chk_workspace_status CHECK ((status = ANY (ARRAY['open'::text, 'locked'::text, 'finalized'::text])))
);


--
-- Name: exhibit_bundles; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.exhibit_bundles (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    case_id uuid NOT NULL,
    bundle_version integer NOT NULL,
    created_by_actor_id uuid NOT NULL,
    labeling_scheme text NOT NULL,
    filing_reference_type text NOT NULL,
    filing_document_id uuid,
    future_filing_type text,
    future_filing_label text,
    status text NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT chk_bundle_status CHECK ((status = ANY (ARRAY['draft'::text, 'finalized'::text, 'exported'::text]))),
    CONSTRAINT chk_filing_reference CHECK ((((filing_reference_type = 'existing'::text) AND (filing_document_id IS NOT NULL)) OR ((filing_reference_type = 'future'::text) AND (future_filing_type IS NOT NULL))))
);


--
-- Name: exhibit_workspace_contributions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.exhibit_workspace_contributions (
    workspace_id uuid NOT NULL,
    actor_id uuid NOT NULL,
    source_document_id uuid NOT NULL,
    proposed_label text,
    proposed_title text,
    added_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: exhibit_workspace_participants; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.exhibit_workspace_participants (
    workspace_id uuid NOT NULL,
    actor_id uuid NOT NULL,
    role text NOT NULL,
    permissions text[] NOT NULL
);


--
-- Name: exhibits; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.exhibits (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    bundle_id uuid NOT NULL,
    source_document_id uuid NOT NULL,
    exhibit_label text NOT NULL,
    content_hash text NOT NULL,
    binary_pointer text NOT NULL,
    page_range text
);


--
-- Name: filing_bundles; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.filing_bundles (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    case_id uuid NOT NULL,
    legal_action_id uuid NOT NULL,
    intent_hash text NOT NULL,
    structured_artifacts_json jsonb NOT NULL,
    structural_validation_json jsonb NOT NULL,
    frozen boolean DEFAULT false NOT NULL,
    version integer DEFAULT 1 NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    exhibit_linkage_c14n_json jsonb,
    version_hashes_c14n_json jsonb,
    bundle_hash text NOT NULL,
    freeze_level text DEFAULT 'prepared'::text,
    bundle_version integer DEFAULT 1 NOT NULL,
    committed_at timestamp with time zone,
    committed_by_actor_id uuid,
    event_cursor bigint DEFAULT 0 NOT NULL,
    exhibit_manifest_c14n_json jsonb DEFAULT '[]'::jsonb NOT NULL,
    render_inputs_hash text,
    previous_bundle_hash text,
    CONSTRAINT filing_bundles_bundle_hash_sha256_chk CHECK ((bundle_hash ~ '^[a-f0-9]{64}$'::text)),
    CONSTRAINT filing_bundles_committed_metadata_chk CHECK ((((freeze_level = 'committed'::text) AND (committed_at IS NOT NULL)) OR ((freeze_level = 'prepared'::text) AND (committed_at IS NULL)))),
    CONSTRAINT filing_bundles_freeze_level_chk CHECK ((freeze_level = ANY (ARRAY['prepared'::text, 'committed'::text]))),
    CONSTRAINT filing_bundles_frozen_freeze_level_chk CHECK ((((frozen = true) AND (freeze_level = 'committed'::text)) OR ((frozen = false) AND (freeze_level = 'prepared'::text))))
);


--
-- Name: filing_documents; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.filing_documents (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    tenant_id uuid NOT NULL,
    case_id uuid NOT NULL,
    legal_action_id uuid NOT NULL,
    bundle_id uuid NOT NULL,
    document_type text NOT NULL,
    file_hash text NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    storage_key text NOT NULL,
    proof_type text,
    proof_json jsonb,
    packet_manifest_hash text,
    source_document_hashes_c14n_json jsonb
);


--
-- Name: idempotency_keys; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.idempotency_keys (
    id bigint NOT NULL,
    tenant_id uuid NOT NULL,
    actor_id uuid NOT NULL,
    endpoint text NOT NULL,
    idempotency_key text NOT NULL,
    request_hash text NOT NULL,
    response_code integer NOT NULL,
    response_body jsonb NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    expires_at timestamp with time zone NOT NULL
);


--
-- Name: idempotency_keys_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.idempotency_keys_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: idempotency_keys_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.idempotency_keys_id_seq OWNED BY public.idempotency_keys.id;


--
-- Name: judicial_case_access; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.judicial_case_access (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    tenant_id uuid NOT NULL,
    judicial_user_id uuid NOT NULL,
    case_id uuid NOT NULL,
    granted_by uuid,
    granted_at timestamp with time zone NOT NULL,
    revoked_at timestamp with time zone,
    reason text
);


--
-- Name: jurisdiction_boundary_datasets; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.jurisdiction_boundary_datasets (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    state_code text NOT NULL,
    unit_type text NOT NULL,
    source_url text NOT NULL,
    source_label text DEFAULT 'Automated Import'::text NOT NULL,
    source_hash_sha256 text NOT NULL,
    effective_from date NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: jurisdiction_boundary_ingest_runs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.jurisdiction_boundary_ingest_runs (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    dataset_id uuid NOT NULL,
    status text NOT NULL,
    started_at timestamp with time zone DEFAULT now() NOT NULL,
    finished_at timestamp with time zone,
    notes text,
    metrics jsonb DEFAULT '{}'::jsonb NOT NULL,
    error_message text,
    CONSTRAINT jurisdiction_boundary_ingest_runs_status_check CHECK ((status = ANY (ARRAY['running'::text, 'success'::text, 'failed'::text, 'skipped_nochange'::text])))
);


--
-- Name: jurisdiction_unit_boundaries; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.jurisdiction_unit_boundaries (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    jurisdiction_unit_id uuid NOT NULL,
    boundary_geometry public.geometry(MultiPolygon,4326) NOT NULL,
    boundary_hash text NOT NULL,
    effective_from timestamp with time zone DEFAULT now() NOT NULL,
    effective_to timestamp with time zone,
    source text,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    dataset_id uuid NOT NULL,
    dataset_hash_sha256 text
);


--
-- Name: jurisdiction_units; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.jurisdiction_units (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    state_code text NOT NULL,
    unit_type text NOT NULL,
    name text NOT NULL,
    parent_id uuid,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    boundary_geometry public.geometry(MultiPolygon,4326),
    CONSTRAINT jurisdiction_units_boundary_is_valid CHECK (((boundary_geometry IS NULL) OR public.st_isvalid(boundary_geometry))),
    CONSTRAINT jurisdiction_units_boundary_srid_4326 CHECK (((boundary_geometry IS NULL) OR (public.st_srid(boundary_geometry) = 4326))),
    CONSTRAINT jurisdiction_units_geometry_allowed_types CHECK (((boundary_geometry IS NULL) OR (unit_type = ANY (ARRAY['county'::text, 'justice_precinct'::text])))),
    CONSTRAINT jurisdiction_units_geometry_only_for_geo_units CHECK (((boundary_geometry IS NULL) OR (unit_type = ANY (ARRAY['justice_precinct'::text, 'county'::text]))))
);


--
-- Name: jurisdiction_boundary_overlaps_current; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.jurisdiction_boundary_overlaps_current AS
 SELECT ju_a.state_code,
    ju_a.name AS unit_a_name,
    ju_b.name AS unit_b_name,
    a.jurisdiction_unit_id AS unit_a,
    b.jurisdiction_unit_id AS unit_b,
    a.id AS boundary_a_id,
    b.id AS boundary_b_id,
    public.st_area(public.st_intersection(a.boundary_geometry, b.boundary_geometry)) AS overlap_area
   FROM (((public.jurisdiction_unit_boundaries a
     JOIN public.jurisdiction_unit_boundaries b ON (((a.effective_to IS NULL) AND (b.effective_to IS NULL) AND (a.jurisdiction_unit_id < b.jurisdiction_unit_id))))
     JOIN public.jurisdiction_units ju_a ON ((ju_a.id = a.jurisdiction_unit_id)))
     JOIN public.jurisdiction_units ju_b ON ((ju_b.id = b.jurisdiction_unit_id)))
  WHERE ((ju_a.unit_type = ju_b.unit_type) AND (ju_a.state_code = ju_b.state_code) AND (public.st_area(public.st_intersection(a.boundary_geometry, b.boundary_geometry)) > (0.000000001)::double precision));


--
-- Name: jurisdiction_boundary_update_changes; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.jurisdiction_boundary_update_changes (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    run_id uuid NOT NULL,
    jurisdiction_unit_id uuid NOT NULL,
    action text NOT NULL,
    old_boundary_id uuid,
    new_boundary_id uuid,
    old_hash text,
    new_hash text,
    notes jsonb,
    created_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: jurisdiction_boundary_update_runs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.jurisdiction_boundary_update_runs (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    state_code text NOT NULL,
    unit_type text NOT NULL,
    staging_table text NOT NULL,
    name_column text NOT NULL,
    geom_column text NOT NULL,
    source text NOT NULL,
    parent_unit_id uuid,
    dry_run boolean DEFAULT false NOT NULL,
    overlap_epsilon double precision NOT NULL,
    initiated_by uuid,
    status text DEFAULT 'running'::text NOT NULL,
    stats jsonb,
    error text,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    finished_at timestamp with time zone
);


--
-- Name: jurisdiction_geometry_coverage; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.jurisdiction_geometry_coverage (
    state_code text NOT NULL,
    unit_type text NOT NULL,
    required boolean DEFAULT true NOT NULL,
    enforce_strategy text DEFAULT 'block'::text NOT NULL,
    notes text
);


--
-- Name: justice_courts; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.justice_courts (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    court_id uuid NOT NULL,
    precinct_id uuid NOT NULL,
    address text,
    created_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: justice_precincts; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.justice_precincts (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    state_id uuid NOT NULL,
    county_id uuid NOT NULL,
    name text NOT NULL,
    code text,
    created_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: jwt_revocations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.jwt_revocations (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    tenant_id uuid NOT NULL,
    jti text NOT NULL,
    revoked_by uuid,
    revoked_at timestamp with time zone DEFAULT now() NOT NULL,
    reason text
);


--
-- Name: legal_action_catalog; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.legal_action_catalog (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    state_id uuid NOT NULL,
    action_key text NOT NULL,
    display_name text NOT NULL,
    description text NOT NULL,
    category text NOT NULL,
    is_active boolean DEFAULT true NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    phase_key text,
    executor_key text,
    court_level text
);


--
-- Name: legal_action_delivery_rules; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.legal_action_delivery_rules (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    legal_action_id uuid NOT NULL,
    delivery_method_id uuid NOT NULL,
    is_required boolean DEFAULT false NOT NULL,
    is_alternative boolean DEFAULT false NOT NULL,
    min_attempts integer DEFAULT 1 NOT NULL,
    stage text NOT NULL,
    rule_description text NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: legal_action_events; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.legal_action_events (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    tenant_id uuid NOT NULL,
    legal_action_id uuid NOT NULL,
    event_type text NOT NULL,
    event_at timestamp with time zone NOT NULL,
    performed_by uuid,
    payload jsonb
);


--
-- Name: legal_action_prerequisites; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.legal_action_prerequisites (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    legal_action_id uuid NOT NULL,
    prerequisite_type text NOT NULL,
    required boolean DEFAULT true NOT NULL,
    blocking_phase text NOT NULL,
    rule_description text NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: legal_action_request_targets; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.legal_action_request_targets (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    tenant_id uuid NOT NULL,
    case_id uuid NOT NULL,
    action_request_id uuid NOT NULL,
    occupant_id uuid,
    target_scope text NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT legal_action_request_targets_target_scope_check CHECK ((target_scope = ANY (ARRAY['all'::text, 'single'::text])))
);


--
-- Name: legal_action_step_requirements; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.legal_action_step_requirements (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    step_id uuid NOT NULL,
    requirement_key text NOT NULL,
    requirement_type text NOT NULL,
    description text NOT NULL,
    is_mandatory boolean DEFAULT true NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: legal_action_steps; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.legal_action_steps (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    legal_action_id uuid NOT NULL,
    step_key text NOT NULL,
    display_name text NOT NULL,
    step_order integer NOT NULL,
    phase text NOT NULL,
    requires_court boolean DEFAULT false NOT NULL,
    requires_sheriff boolean DEFAULT false NOT NULL,
    is_conditional boolean DEFAULT false NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: legal_action_title_gates; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.legal_action_title_gates (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    legal_action_id uuid NOT NULL,
    required_event_type text NOT NULL,
    must_be_recorded boolean DEFAULT false NOT NULL,
    blocking_stage text NOT NULL,
    rule_description text NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: legal_action_venue_rules; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.legal_action_venue_rules (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    legal_action_id uuid NOT NULL,
    state_code text NOT NULL,
    county_id uuid,
    court_type text NOT NULL,
    jurisdiction text NOT NULL,
    venue_scope text NOT NULL,
    filing_stage text NOT NULL,
    required boolean DEFAULT true NOT NULL,
    rule_description text NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: legal_actions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.legal_actions (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    tenant_id uuid NOT NULL,
    case_id uuid NOT NULL,
    action_type text NOT NULL,
    status text NOT NULL,
    initiated_at timestamp with time zone NOT NULL,
    created_by uuid,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    parent_legal_action_id uuid,
    origin text DEFAULT 'system'::text NOT NULL,
    court_name text,
    court_type text,
    court_city text,
    court_state text,
    court_county text,
    court_case_number text,
    intent_hash text,
    filing_attorney_actor_id uuid
);


--
-- Name: COLUMN legal_actions.filing_attorney_actor_id; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.legal_actions.filing_attorney_actor_id IS 'Actor explicitly assigned as filing attorney/attorney of record for this legal action.';


--
-- Name: legal_holds; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.legal_holds (
    id uuid NOT NULL,
    tenant_id uuid NOT NULL,
    scope_type text NOT NULL,
    scope_id uuid NOT NULL,
    reason text NOT NULL,
    placed_by uuid NOT NULL,
    placed_at timestamp without time zone DEFAULT now() NOT NULL,
    released_at timestamp without time zone
);


--
-- Name: legal_phases; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.legal_phases (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    phase_key text NOT NULL,
    display_name text NOT NULL,
    display_order integer NOT NULL,
    description text NOT NULL,
    is_litigation boolean DEFAULT true NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: lifecycle_events; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.lifecycle_events (
    id bigint NOT NULL,
    tenant_id uuid NOT NULL,
    entity_type text NOT NULL,
    entity_id uuid NOT NULL,
    event_type text NOT NULL,
    payload jsonb NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: lifecycle_events_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.lifecycle_events_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: lifecycle_events_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.lifecycle_events_id_seq OWNED BY public.lifecycle_events.id;


--
-- Name: missing_action_documents; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.missing_action_documents AS
 SELECT ade.state_code,
    ade.legal_action_key,
    ade.requested_action,
    ade.phase_key,
    ade.document_type_key,
    ade.blocks_completion,
    cd.id AS case_document_id
   FROM (public.action_document_expectations ade
     LEFT JOIN public.case_documents cd ON (((cd.document_type_key = ade.document_type_key) AND (cd.status = 'final'::text))))
  WHERE ((ade.required = true) AND (cd.id IS NULL));


--
-- Name: outbound_messages; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.outbound_messages (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    tenant_id uuid NOT NULL,
    message_type text NOT NULL,
    provider public.message_provider NOT NULL,
    recipient_email text NOT NULL,
    payload_snapshot jsonb,
    sent_at timestamp with time zone,
    delivery_status text,
    provider_message_id text,
    created_by uuid
);


--
-- Name: phase_document_expectations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.phase_document_expectations (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    tenant_id uuid,
    state_code text NOT NULL,
    legal_action_key text NOT NULL,
    phase_key text NOT NULL,
    document_type_key text NOT NULL,
    requirement_level text NOT NULL,
    source_type text NOT NULL,
    must_be_final boolean DEFAULT true NOT NULL,
    allow_multiple boolean DEFAULT false NOT NULL,
    conditions jsonb DEFAULT '{}'::jsonb NOT NULL,
    is_active boolean DEFAULT true NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT phase_document_expectations_requirement_level_check CHECK ((requirement_level = ANY (ARRAY['required'::text, 'optional'::text, 'conditional'::text]))),
    CONSTRAINT phase_document_expectations_source_type_check CHECK ((source_type = ANY (ARRAY['system_generated'::text, 'external'::text, 'court_issued'::text])))
);


--
-- Name: phase_transitions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.phase_transitions (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    tenant_id uuid,
    state_code text NOT NULL,
    legal_action_key text NOT NULL,
    from_phase_key text NOT NULL,
    on_event_type text NOT NULL,
    to_requested_action text NOT NULL,
    auto_approve boolean DEFAULT true NOT NULL,
    auto_execute boolean DEFAULT true NOT NULL,
    conditions jsonb DEFAULT '{}'::jsonb NOT NULL,
    is_active boolean DEFAULT true NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: procedural_action_requirements; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.procedural_action_requirements (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    phase_key text NOT NULL,
    action_type text NOT NULL,
    requires_evidence boolean DEFAULT true NOT NULL,
    min_documents integer DEFAULT 1 NOT NULL,
    requires_delivery boolean DEFAULT false NOT NULL,
    allowed_delivery_methods text[],
    requires_tracking_number boolean DEFAULT false NOT NULL,
    jurisdiction_state text,
    jurisdiction_county text,
    court_type text,
    created_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: procedural_deadline_templates; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.procedural_deadline_templates (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    state_code text NOT NULL,
    legal_action_key text NOT NULL,
    trigger_phase_key text NOT NULL,
    obligation_key text NOT NULL,
    display_name text NOT NULL,
    description text,
    anchor_key text NOT NULL,
    offset_days integer DEFAULT 0 NOT NULL,
    required boolean DEFAULT true NOT NULL,
    blocks_phase_key text,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT chk_pdt_anchor_key CHECK ((anchor_key = ANY (ARRAY['trial_date'::text, 'service_date'::text, 'hearing_date'::text, 'custom'::text])))
);


--
-- Name: procedural_phase_actions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.procedural_phase_actions (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    phase_key text NOT NULL,
    action_type text NOT NULL,
    required boolean DEFAULT false NOT NULL,
    auto_generate boolean DEFAULT false NOT NULL,
    blocking boolean DEFAULT false NOT NULL,
    "order" integer DEFAULT 0 NOT NULL,
    jurisdiction_state text,
    jurisdiction_county text,
    court_type text,
    created_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: process_service_event_evidence; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.process_service_event_evidence (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    tenant_id uuid NOT NULL,
    process_service_event_id uuid NOT NULL,
    document_id uuid NOT NULL,
    role text NOT NULL,
    metadata jsonb DEFAULT '{}'::jsonb NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    created_by uuid NOT NULL
);


--
-- Name: process_service_events; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.process_service_events (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    tenant_id uuid NOT NULL,
    case_id uuid NOT NULL,
    property_id uuid NOT NULL,
    served_actor_id uuid,
    served_name text NOT NULL,
    service_method text NOT NULL,
    service_date date NOT NULL,
    service_time time without time zone,
    service_location text,
    server_name text,
    outcome text NOT NULL,
    failure_reason text,
    created_by uuid NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    served_party_role text,
    server_type text,
    server_registration text,
    tracking_number text,
    mail_carrier text,
    affidavit_document_id uuid,
    metadata jsonb DEFAULT '{}'::jsonb NOT NULL,
    CONSTRAINT chk_process_service_outcome CHECK ((outcome = ANY (ARRAY['completed'::text, 'failed'::text, 'attempted'::text])))
);


--
-- Name: properties; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.properties (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    tenant_id uuid NOT NULL,
    address_line1 text NOT NULL,
    address_line2 text,
    city text NOT NULL,
    state text NOT NULL,
    zip text NOT NULL,
    county text,
    apn text,
    legal_description text NOT NULL,
    geo_lat double precision,
    geo_lng double precision,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    source text NOT NULL,
    owner_actor_id uuid NOT NULL,
    acquisition_date date,
    acquisition_method text,
    deed_references jsonb,
    jurisdiction_refs jsonb,
    chain_of_title_notes text,
    CONSTRAINT properties_geo_lat_lng_valid CHECK ((((geo_lat IS NULL) AND (geo_lng IS NULL)) OR (((geo_lat >= ('-90'::integer)::double precision) AND (geo_lat <= (90)::double precision)) AND ((geo_lng >= ('-180'::integer)::double precision) AND (geo_lng <= (180)::double precision))))),
    CONSTRAINT properties_valid_lat_lng CHECK ((((geo_lat IS NULL) AND (geo_lng IS NULL)) OR (((geo_lat >= ('-90'::integer)::double precision) AND (geo_lat <= (90)::double precision)) AND ((geo_lng >= ('-180'::integer)::double precision) AND (geo_lng <= (180)::double precision)))))
);


--
-- Name: property_image_derivatives; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.property_image_derivatives (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    tenant_id uuid NOT NULL,
    image_id uuid NOT NULL,
    variant text NOT NULL,
    mime_type text NOT NULL,
    storage_uri text NOT NULL,
    content_hash text NOT NULL,
    width integer NOT NULL,
    height integer NOT NULL,
    created_by uuid NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    deleted_at timestamp with time zone,
    deleted_by uuid,
    CONSTRAINT property_image_derivatives_storage_uri_check CHECK (((storage_uri ~ '^local://property-images-thumbs/[0-9a-f-]{36}$'::text) OR (storage_uri ~ '^s3://[a-z0-9.-]+/property-images-thumbs/[0-9a-f-]{36}$'::text)))
);


--
-- Name: property_images; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.property_images (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    tenant_id uuid NOT NULL,
    property_id uuid NOT NULL,
    mime_type text NOT NULL,
    storage_uri text NOT NULL,
    content_hash text NOT NULL,
    metadata jsonb NOT NULL,
    created_by uuid NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    deleted_at timestamp with time zone,
    deleted_by uuid,
    CONSTRAINT property_images_storage_uri_check CHECK (((storage_uri ~ '^local://property-images/[0-9a-f-]{36}$'::text) OR (storage_uri ~ '^s3://[a-z0-9.-]+/property-images/[0-9a-f-]{36}$'::text)))
);


--
-- Name: property_jurisdiction_assignments; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.property_jurisdiction_assignments (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    property_id uuid NOT NULL,
    jurisdiction_type text NOT NULL,
    jurisdiction_unit_id uuid NOT NULL,
    boundary_id uuid NOT NULL,
    resolution_method text NOT NULL,
    coordinate_lat double precision NOT NULL,
    coordinate_lng double precision NOT NULL,
    resolved_at timestamp with time zone DEFAULT now() NOT NULL,
    valid_from timestamp with time zone DEFAULT now() NOT NULL,
    valid_to timestamp with time zone,
    source_metadata jsonb DEFAULT '{}'::jsonb NOT NULL,
    CONSTRAINT pja_dates_chk CHECK (((valid_to IS NULL) OR (valid_to > valid_from))),
    CONSTRAINT pja_lat_lng_chk CHECK ((((coordinate_lat >= ('-90'::integer)::double precision) AND (coordinate_lat <= (90)::double precision)) AND ((coordinate_lng >= ('-180'::integer)::double precision) AND (coordinate_lng <= (180)::double precision)))),
    CONSTRAINT pja_metadata_object_chk CHECK ((jsonb_typeof(source_metadata) = 'object'::text)),
    CONSTRAINT pja_method_nonempty_chk CHECK ((length(btrim(resolution_method)) > 0)),
    CONSTRAINT pja_type_chk CHECK ((jurisdiction_type = ANY (ARRAY['county'::text, 'justice_precinct'::text])))
);


--
-- Name: property_relationships; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.property_relationships (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    tenant_id uuid NOT NULL,
    property_id uuid NOT NULL,
    actor_id uuid NOT NULL,
    role public.party_role NOT NULL,
    starts_at timestamp with time zone NOT NULL,
    ends_at timestamp with time zone,
    is_primary boolean DEFAULT false,
    percentage_interest numeric(5,2),
    reference_number text,
    notes text,
    created_by uuid,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    scope_type text,
    scope_id uuid,
    metadata jsonb DEFAULT '{}'::jsonb NOT NULL,
    custom_role_label text,
    ownership_status text,
    ownership_basis text,
    CONSTRAINT chk_owner_percentage_requires_adjudication CHECK (((role <> 'owner'::public.party_role) OR (percentage_interest IS NULL) OR (ownership_status = 'adjudicated'::text))),
    CONSTRAINT chk_relationship_dates CHECK (((ends_at IS NULL) OR (ends_at > starts_at)))
);


--
-- Name: COLUMN property_relationships.custom_role_label; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.property_relationships.custom_role_label IS 'UI-only descriptive label; does not affect legal role semantics';


--
-- Name: property_supersession_events; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.property_supersession_events (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    tenant_id uuid NOT NULL,
    prior_property_id uuid NOT NULL,
    new_property_id uuid NOT NULL,
    reason text NOT NULL,
    created_by uuid,
    created_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: purchaser_entities; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.purchaser_entities (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    tenant_id uuid NOT NULL,
    owner_actor_id uuid NOT NULL,
    legal_name text NOT NULL,
    entity_type text NOT NULL,
    state_of_org text,
    ein_last4 text,
    mailing_address jsonb,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT purchaser_entities_ein_last4_check CHECK (((ein_last4 ~ '^[0-9]{4}$'::text) OR (ein_last4 IS NULL))),
    CONSTRAINT purchaser_entities_entity_type_check CHECK ((entity_type = ANY (ARRAY['individual'::text, 'llc'::text, 'corp'::text, 'trust'::text, 'partnership'::text, 'other'::text])))
);


--
-- Name: role_scopes; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.role_scopes (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    tenant_id uuid NOT NULL,
    role_name text NOT NULL,
    scope text NOT NULL
);


--
-- Name: roles; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.roles (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    tenant_id uuid NOT NULL,
    name text NOT NULL
);


--
-- Name: secure_access_links; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.secure_access_links (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    tenant_id uuid NOT NULL,
    case_id uuid NOT NULL,
    document_id uuid NOT NULL,
    issued_to_email text NOT NULL,
    issued_by uuid,
    issued_at timestamp with time zone NOT NULL,
    expires_at timestamp with time zone,
    max_downloads integer,
    downloads_used integer DEFAULT 0 NOT NULL,
    revoked_at timestamp with time zone,
    access_token_hash text NOT NULL
);


--
-- Name: service_executions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.service_executions (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    tenant_id uuid NOT NULL,
    case_id uuid NOT NULL,
    legal_action_id uuid NOT NULL,
    execution_type public.execution_type NOT NULL,
    status text NOT NULL,
    created_by uuid,
    created_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: service_tasks; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.service_tasks (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    tenant_id uuid NOT NULL,
    case_id uuid NOT NULL,
    action_request_id uuid NOT NULL,
    occupant_id uuid,
    document_id uuid NOT NULL,
    service_method text NOT NULL,
    status text NOT NULL,
    assigned_to_actor_id uuid,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    completed_at timestamp with time zone,
    CONSTRAINT service_tasks_service_method_check CHECK ((service_method = ANY (ARRAY['posting'::text, 'certified_mail'::text, 'courier'::text, 'personal_service'::text]))),
    CONSTRAINT service_tasks_status_check CHECK ((status = ANY (ARRAY['pending'::text, 'in_progress'::text, 'completed'::text, 'failed'::text])))
);


--
-- Name: staging_counties; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.staging_counties (
    ogc_fid integer NOT NULL,
    statefp character varying(2),
    countyfp character varying(3),
    countyns character varying(8),
    geoid character varying(5),
    geoidfq character varying(14),
    name character varying(100),
    namelsad character varying(100),
    lsad character varying(2),
    classfp character varying(2),
    mtfcc character varying(5),
    csafp character varying(3),
    cbsafp character varying(5),
    metdivfp character varying(5),
    funcstat character varying(1),
    aland numeric(14,0),
    awater numeric(14,0),
    intptlat character varying(11),
    intptlon character varying(12),
    wkb_geometry public.geometry(MultiPolygon,4326)
);


--
-- Name: staging_counties_ogc_fid_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.staging_counties_ogc_fid_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: staging_counties_ogc_fid_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.staging_counties_ogc_fid_seq OWNED BY public.staging_counties.ogc_fid;


--
-- Name: staging_maricopa_precincts; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.staging_maricopa_precincts (
    ogc_fid integer NOT NULL,
    objectid numeric(2,0),
    bdname character varying(17),
    districtfu character varying(34),
    wkb_geometry public.geometry(MultiPolygon,4326)
);


--
-- Name: staging_maricopa_precincts_ogc_fid_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.staging_maricopa_precincts_ogc_fid_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: staging_maricopa_precincts_ogc_fid_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.staging_maricopa_precincts_ogc_fid_seq OWNED BY public.staging_maricopa_precincts.ogc_fid;


--
-- Name: states; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.states (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    code text NOT NULL,
    name text NOT NULL,
    country_code text DEFAULT 'US'::text NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: tenant_client_profiles; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.tenant_client_profiles (
    tenant_id uuid NOT NULL,
    client_actor_id uuid NOT NULL,
    client_type text NOT NULL,
    starts_at timestamp with time zone NOT NULL,
    ends_at timestamp with time zone,
    created_by uuid,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT tenant_client_profiles_client_type_check CHECK ((client_type = ANY (ARRAY['represented'::text, 'self_represented'::text, 'one_off'::text])))
);


--
-- Name: tenant_event_type_permissions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.tenant_event_type_permissions (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    tenant_id uuid NOT NULL,
    legal_kind text NOT NULL,
    can_create_roles text[] NOT NULL
);


--
-- Name: tenant_invitations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.tenant_invitations (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    tenant_id uuid NOT NULL,
    email text NOT NULL,
    invited_roles text[] DEFAULT '{}'::text[] NOT NULL,
    token_hash text NOT NULL,
    issued_by uuid,
    issued_at timestamp with time zone DEFAULT now() NOT NULL,
    expires_at timestamp with time zone NOT NULL,
    accepted_at timestamp with time zone,
    revoked_at timestamp with time zone
);


--
-- Name: tenant_phase_permissions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.tenant_phase_permissions (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    tenant_id uuid NOT NULL,
    phase text NOT NULL,
    can_view_roles text[] NOT NULL,
    can_create_roles text[] NOT NULL,
    can_edit_roles text[] NOT NULL,
    can_delete_roles text[] NOT NULL
);


--
-- Name: tenant_properties; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.tenant_properties (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    tenant_id uuid NOT NULL,
    property_id uuid NOT NULL,
    added_by_user_id uuid,
    portfolio_status text DEFAULT 'watching'::text NOT NULL,
    private_metadata jsonb DEFAULT '{}'::jsonb NOT NULL,
    legacy_owner_actor_id uuid,
    acquisition_date date,
    acquisition_method text,
    legacy_deed_references jsonb,
    chain_of_title_notes text,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT tenant_properties_private_metadata_object CHECK ((jsonb_typeof(private_metadata) = 'object'::text)),
    CONSTRAINT tenant_properties_status_nonempty CHECK ((length(btrim(portfolio_status)) > 0))
);


--
-- Name: tenant_timeline_policies; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.tenant_timeline_policies (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    tenant_id uuid NOT NULL,
    default_view_mode text NOT NULL,
    visible_phases text[] NOT NULL,
    default_expanded_phases text[] NOT NULL,
    default_filters jsonb DEFAULT '{}'::jsonb NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT tenant_timeline_policies_default_view_mode_check CHECK ((default_view_mode = ANY (ARRAY['procedural'::text, 'chronological'::text])))
);


--
-- Name: tenants; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.tenants (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    slug text NOT NULL,
    name text NOT NULL,
    status text NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: title_events; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.title_events (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    tenant_id uuid NOT NULL,
    case_id uuid NOT NULL,
    property_id uuid NOT NULL,
    purchaser_entity_id uuid NOT NULL,
    event_type text NOT NULL,
    event_date date NOT NULL,
    recording_number text,
    recording_book text,
    recording_page text,
    county_id uuid NOT NULL,
    created_by uuid NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: trustee_deed_events; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.trustee_deed_events (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    tenant_id uuid NOT NULL,
    case_id uuid NOT NULL,
    property_id uuid NOT NULL,
    trustee_sale_id uuid NOT NULL,
    recording_date date NOT NULL,
    recording_number text NOT NULL,
    recorder_office text NOT NULL,
    deed_document_id uuid,
    created_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: trustee_sale_events; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.trustee_sale_events (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    tenant_id uuid NOT NULL,
    case_id uuid NOT NULL,
    property_id uuid NOT NULL,
    purchaser_entity_id uuid NOT NULL,
    auction_date date NOT NULL,
    auction_location text,
    trustee_name text,
    sale_number text,
    bid_amount numeric(14,2),
    deed_recorded boolean DEFAULT false NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: user_credentials; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.user_credentials (
    user_id uuid NOT NULL,
    password_hash text NOT NULL,
    password_updated_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: user_roles; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.user_roles (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    tenant_id uuid NOT NULL,
    user_id uuid NOT NULL,
    role_id uuid NOT NULL,
    starts_at timestamp with time zone NOT NULL,
    ends_at timestamp with time zone
);


--
-- Name: users; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.users (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    tenant_id uuid NOT NULL,
    email text NOT NULL,
    display_name text,
    status text NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: v_phase_document_satisfaction; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.v_phase_document_satisfaction AS
 SELECT pde.tenant_id,
    pde.state_code,
    pde.legal_action_key,
    pde.phase_key,
    pde.document_type_key,
    count(cd.id) FILTER (WHERE (cd.status = 'final'::text)) AS finalized_count,
    bool_or((cd.status = 'final'::text)) AS has_final_document
   FROM (public.phase_document_expectations pde
     LEFT JOIN public.case_documents cd ON (((cd.document_type_key = pde.document_type_key) AND (cd.status = 'final'::text))))
  WHERE (pde.is_active = true)
  GROUP BY pde.tenant_id, pde.state_code, pde.legal_action_key, pde.phase_key, pde.document_type_key;


--
-- Name: venue_resolution_audits; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.venue_resolution_audits (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    tenant_id uuid NOT NULL,
    case_id uuid NOT NULL,
    legal_action_id uuid NOT NULL,
    stage text NOT NULL,
    state_code text NOT NULL,
    court_type_code text NOT NULL,
    required_unit_type text NOT NULL,
    resolution_strategy text NOT NULL,
    property_id uuid,
    geo_lat double precision,
    geo_lng double precision,
    parent_id uuid,
    resolved_jurisdiction_unit_id uuid,
    resolved_boundary_id uuid,
    resolved_court_id uuid,
    outcome text NOT NULL,
    reason_code text,
    candidates_count integer DEFAULT 0 NOT NULL,
    overlap_detected boolean DEFAULT false NOT NULL,
    details jsonb DEFAULT '{}'::jsonb NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    resolved_dataset_id uuid,
    as_of_date date NOT NULL,
    resolved_boundary_hash text,
    resolved_dataset_hash_sha256 text,
    resolver_version text,
    strict_overlap_mode boolean DEFAULT false NOT NULL
);


--
-- Name: venue_rules; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.venue_rules (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    state_id uuid NOT NULL,
    legal_action text NOT NULL,
    required_court_type text NOT NULL,
    venue_scope text NOT NULL,
    requires_trustee_deed boolean DEFAULT false NOT NULL,
    requires_notice_delivery boolean DEFAULT true NOT NULL,
    requires_sale_date boolean DEFAULT false NOT NULL,
    description text,
    created_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: api_idempotency_keys id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.api_idempotency_keys ALTER COLUMN id SET DEFAULT nextval('public.api_idempotency_keys_id_seq'::regclass);


--
-- Name: idempotency_keys id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.idempotency_keys ALTER COLUMN id SET DEFAULT nextval('public.idempotency_keys_id_seq'::regclass);


--
-- Name: lifecycle_events id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.lifecycle_events ALTER COLUMN id SET DEFAULT nextval('public.lifecycle_events_id_seq'::regclass);


--
-- Name: staging_counties ogc_fid; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.staging_counties ALTER COLUMN ogc_fid SET DEFAULT nextval('public.staging_counties_ogc_fid_seq'::regclass);


--
-- Name: staging_maricopa_precincts ogc_fid; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.staging_maricopa_precincts ALTER COLUMN ogc_fid SET DEFAULT nextval('public.staging_maricopa_precincts_ogc_fid_seq'::regclass);


--
-- Name: action_document_expectations action_document_expectations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.action_document_expectations
    ADD CONSTRAINT action_document_expectations_pkey PRIMARY KEY (id);


--
-- Name: action_document_expectations action_document_expectations_tenant_id_state_code_legal_act_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.action_document_expectations
    ADD CONSTRAINT action_document_expectations_tenant_id_state_code_legal_act_key UNIQUE (tenant_id, state_code, legal_action_key, requested_action, document_type_key);


--
-- Name: actor_identities actor_identities_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.actor_identities
    ADD CONSTRAINT actor_identities_pkey PRIMARY KEY (id);


--
-- Name: actors actors_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.actors
    ADD CONSTRAINT actors_pkey PRIMARY KEY (id);


--
-- Name: api_idempotency_keys api_idempotency_keys_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.api_idempotency_keys
    ADD CONSTRAINT api_idempotency_keys_pkey PRIMARY KEY (id);


--
-- Name: api_idempotency_keys api_idempotency_keys_tenant_id_idempotency_key_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.api_idempotency_keys
    ADD CONSTRAINT api_idempotency_keys_tenant_id_idempotency_key_key UNIQUE (tenant_id, idempotency_key);


--
-- Name: artifact_exhibit_links artifact_exhibit_links_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.artifact_exhibit_links
    ADD CONSTRAINT artifact_exhibit_links_pkey PRIMARY KEY (id);


--
-- Name: assignments assignments_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.assignments
    ADD CONSTRAINT assignments_pkey PRIMARY KEY (id);


--
-- Name: attorney_filing_profiles attorney_filing_profiles_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.attorney_filing_profiles
    ADD CONSTRAINT attorney_filing_profiles_pkey PRIMARY KEY (id);


--
-- Name: audit_events audit_events_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.audit_events
    ADD CONSTRAINT audit_events_pkey PRIMARY KEY (id);


--
-- Name: auth_access_token_revocations auth_access_token_revocations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.auth_access_token_revocations
    ADD CONSTRAINT auth_access_token_revocations_pkey PRIMARY KEY (tenant_id, jti);


--
-- Name: case_capability_grants case_capability_grants_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.case_capability_grants
    ADD CONSTRAINT case_capability_grants_pkey PRIMARY KEY (id);


--
-- Name: case_deadlines case_deadlines_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.case_deadlines
    ADD CONSTRAINT case_deadlines_pkey PRIMARY KEY (id);


--
-- Name: case_documents case_documents_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.case_documents
    ADD CONSTRAINT case_documents_pkey PRIMARY KEY (id);


--
-- Name: case_events case_events_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.case_events
    ADD CONSTRAINT case_events_pkey PRIMARY KEY (id);


--
-- Name: case_ingress_submissions case_ingress_submissions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.case_ingress_submissions
    ADD CONSTRAINT case_ingress_submissions_pkey PRIMARY KEY (id);


--
-- Name: case_lifecycle_events case_lifecycle_events_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.case_lifecycle_events
    ADD CONSTRAINT case_lifecycle_events_pkey PRIMARY KEY (id);


--
-- Name: case_occupant_action_targets case_occupant_action_targets_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.case_occupant_action_targets
    ADD CONSTRAINT case_occupant_action_targets_pkey PRIMARY KEY (id);


--
-- Name: case_parties case_parties_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.case_parties
    ADD CONSTRAINT case_parties_pkey PRIMARY KEY (id);


--
-- Name: case_party_contacts case_party_contacts_case_id_actor_id_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.case_party_contacts
    ADD CONSTRAINT case_party_contacts_case_id_actor_id_key UNIQUE (case_id, actor_id);


--
-- Name: case_party_contacts case_party_contacts_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.case_party_contacts
    ADD CONSTRAINT case_party_contacts_pkey PRIMARY KEY (id);


--
-- Name: case_prerequisite_compliance_evidence case_prerequisite_compliance__compliance_id_document_id_doc_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.case_prerequisite_compliance_evidence
    ADD CONSTRAINT case_prerequisite_compliance__compliance_id_document_id_doc_key UNIQUE (compliance_id, document_id, document_version_id);


--
-- Name: case_prerequisite_compliance_evidence case_prerequisite_compliance_evidence_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.case_prerequisite_compliance_evidence
    ADD CONSTRAINT case_prerequisite_compliance_evidence_pkey PRIMARY KEY (id);


--
-- Name: case_prerequisite_compliance case_prerequisite_compliance_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.case_prerequisite_compliance
    ADD CONSTRAINT case_prerequisite_compliance_pkey PRIMARY KEY (id);


--
-- Name: case_procedural_deadlines case_procedural_deadlines_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.case_procedural_deadlines
    ADD CONSTRAINT case_procedural_deadlines_pkey PRIMARY KEY (id);


--
-- Name: case_procedure_snapshots case_procedure_snapshots_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.case_procedure_snapshots
    ADD CONSTRAINT case_procedure_snapshots_pkey PRIMARY KEY (id);


--
-- Name: case_procedure_snapshots case_procedure_snapshots_tenant_id_case_id_procedure_name_s_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.case_procedure_snapshots
    ADD CONSTRAINT case_procedure_snapshots_tenant_id_case_id_procedure_name_s_key UNIQUE (tenant_id, case_id, procedure_name, snapshot_sequence);


--
-- Name: case_properties case_properties_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.case_properties
    ADD CONSTRAINT case_properties_pkey PRIMARY KEY (id);


--
-- Name: case_venue_assignments case_venue_assignments_case_id_legal_action_id_stage_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.case_venue_assignments
    ADD CONSTRAINT case_venue_assignments_case_id_legal_action_id_stage_key UNIQUE (case_id, legal_action_id, stage);


--
-- Name: case_venue_assignments case_venue_assignments_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.case_venue_assignments
    ADD CONSTRAINT case_venue_assignments_pkey PRIMARY KEY (id);


--
-- Name: case_venue_overrides case_venue_overrides_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.case_venue_overrides
    ADD CONSTRAINT case_venue_overrides_pkey PRIMARY KEY (id);


--
-- Name: case_venue_overrides case_venue_overrides_tenant_id_case_id_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.case_venue_overrides
    ADD CONSTRAINT case_venue_overrides_tenant_id_case_id_key UNIQUE (tenant_id, case_id);


--
-- Name: cases cases_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cases
    ADD CONSTRAINT cases_pkey PRIMARY KEY (id);


--
-- Name: counties counties_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.counties
    ADD CONSTRAINT counties_pkey PRIMARY KEY (id);


--
-- Name: counties counties_state_id_name_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.counties
    ADD CONSTRAINT counties_state_id_name_key UNIQUE (state_id, name);


--
-- Name: court_filing_locations court_filing_locations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.court_filing_locations
    ADD CONSTRAINT court_filing_locations_pkey PRIMARY KEY (id);


--
-- Name: court_precincts court_precincts_court_id_code_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.court_precincts
    ADD CONSTRAINT court_precincts_court_id_code_key UNIQUE (court_id, code);


--
-- Name: court_precincts court_precincts_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.court_precincts
    ADD CONSTRAINT court_precincts_pkey PRIMARY KEY (id);


--
-- Name: court_tier_jurisdiction_requirements court_tier_jurisdiction_requirements_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.court_tier_jurisdiction_requirements
    ADD CONSTRAINT court_tier_jurisdiction_requirements_pkey PRIMARY KEY (court_type_code);


--
-- Name: courts courts_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.courts
    ADD CONSTRAINT courts_pkey PRIMARY KEY (id);


--
-- Name: courts courts_state_id_county_id_court_type_name_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.courts
    ADD CONSTRAINT courts_state_id_county_id_court_type_name_key UNIQUE (state_id, county_id, court_type, name);


--
-- Name: cron_job_runs cron_job_runs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cron_job_runs
    ADD CONSTRAINT cron_job_runs_pkey PRIMARY KEY (id);


--
-- Name: delivery_methods delivery_methods_code_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.delivery_methods
    ADD CONSTRAINT delivery_methods_code_key UNIQUE (code);


--
-- Name: delivery_methods delivery_methods_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.delivery_methods
    ADD CONSTRAINT delivery_methods_pkey PRIMARY KEY (id);


--
-- Name: demand_deliveries demand_deliveries_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.demand_deliveries
    ADD CONSTRAINT demand_deliveries_pkey PRIMARY KEY (id);


--
-- Name: demand_delivery_events demand_delivery_events_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.demand_delivery_events
    ADD CONSTRAINT demand_delivery_events_pkey PRIMARY KEY (id);


--
-- Name: document_capability_grants document_capability_grants_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.document_capability_grants
    ADD CONSTRAINT document_capability_grants_pkey PRIMARY KEY (id);


--
-- Name: document_events document_events_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.document_events
    ADD CONSTRAINT document_events_pkey PRIMARY KEY (id);


--
-- Name: document_exports document_exports_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.document_exports
    ADD CONSTRAINT document_exports_pkey PRIMARY KEY (id);


--
-- Name: document_groupings document_groupings_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.document_groupings
    ADD CONSTRAINT document_groupings_pkey PRIMARY KEY (id);


--
-- Name: document_template_versions document_template_versions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.document_template_versions
    ADD CONSTRAINT document_template_versions_pkey PRIMARY KEY (tenant_id, template_key, version);


--
-- Name: document_types document_types_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.document_types
    ADD CONSTRAINT document_types_pkey PRIMARY KEY (id);


--
-- Name: document_types document_types_tenant_id_type_key_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.document_types
    ADD CONSTRAINT document_types_tenant_id_type_key_key UNIQUE (tenant_id, type_key);


--
-- Name: document_versions document_versions_document_id_version_number_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.document_versions
    ADD CONSTRAINT document_versions_document_id_version_number_key UNIQUE (document_id, version_number);


--
-- Name: document_versions document_versions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.document_versions
    ADD CONSTRAINT document_versions_pkey PRIMARY KEY (id);


--
-- Name: documents documents_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.documents
    ADD CONSTRAINT documents_pkey PRIMARY KEY (id);


--
-- Name: event_replay_audit event_replay_audit_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.event_replay_audit
    ADD CONSTRAINT event_replay_audit_pkey PRIMARY KEY (id);


--
-- Name: evidence_access_grants evidence_access_grants_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.evidence_access_grants
    ADD CONSTRAINT evidence_access_grants_pkey PRIMARY KEY (id);


--
-- Name: evidence_access_grants evidence_access_grants_token_hash_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.evidence_access_grants
    ADD CONSTRAINT evidence_access_grants_token_hash_key UNIQUE (token_hash);


--
-- Name: evidence_items evidence_items_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.evidence_items
    ADD CONSTRAINT evidence_items_pkey PRIMARY KEY (id);


--
-- Name: exhibit_bundle_workspaces exhibit_bundle_workspaces_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.exhibit_bundle_workspaces
    ADD CONSTRAINT exhibit_bundle_workspaces_pkey PRIMARY KEY (id);


--
-- Name: exhibit_bundles exhibit_bundles_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.exhibit_bundles
    ADD CONSTRAINT exhibit_bundles_pkey PRIMARY KEY (id);


--
-- Name: exhibit_workspace_contributions exhibit_workspace_contributions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.exhibit_workspace_contributions
    ADD CONSTRAINT exhibit_workspace_contributions_pkey PRIMARY KEY (workspace_id, actor_id, source_document_id);


--
-- Name: exhibit_workspace_participants exhibit_workspace_participants_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.exhibit_workspace_participants
    ADD CONSTRAINT exhibit_workspace_participants_pkey PRIMARY KEY (workspace_id, actor_id);


--
-- Name: exhibits exhibits_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.exhibits
    ADD CONSTRAINT exhibits_pkey PRIMARY KEY (id);


--
-- Name: filing_bundles filing_bundles_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.filing_bundles
    ADD CONSTRAINT filing_bundles_pkey PRIMARY KEY (id);


--
-- Name: filing_documents filing_documents_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.filing_documents
    ADD CONSTRAINT filing_documents_pkey PRIMARY KEY (id);


--
-- Name: filing_documents filing_documents_tenant_id_bundle_id_document_type_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.filing_documents
    ADD CONSTRAINT filing_documents_tenant_id_bundle_id_document_type_key UNIQUE (tenant_id, bundle_id, document_type);


--
-- Name: filing_documents filing_documents_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.filing_documents
    ADD CONSTRAINT filing_documents_unique UNIQUE (bundle_id, document_type);


--
-- Name: idempotency_keys idempotency_keys_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.idempotency_keys
    ADD CONSTRAINT idempotency_keys_pkey PRIMARY KEY (id);


--
-- Name: judicial_case_access judicial_case_access_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.judicial_case_access
    ADD CONSTRAINT judicial_case_access_pkey PRIMARY KEY (id);


--
-- Name: jurisdiction_boundary_datasets jurisdiction_boundary_dataset_state_code_unit_type_source_h_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.jurisdiction_boundary_datasets
    ADD CONSTRAINT jurisdiction_boundary_dataset_state_code_unit_type_source_h_key UNIQUE (state_code, unit_type, source_hash_sha256);


--
-- Name: jurisdiction_boundary_datasets jurisdiction_boundary_datasets_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.jurisdiction_boundary_datasets
    ADD CONSTRAINT jurisdiction_boundary_datasets_pkey PRIMARY KEY (id);


--
-- Name: jurisdiction_boundary_ingest_runs jurisdiction_boundary_ingest_runs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.jurisdiction_boundary_ingest_runs
    ADD CONSTRAINT jurisdiction_boundary_ingest_runs_pkey PRIMARY KEY (id);


--
-- Name: jurisdiction_boundary_update_changes jurisdiction_boundary_update_changes_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.jurisdiction_boundary_update_changes
    ADD CONSTRAINT jurisdiction_boundary_update_changes_pkey PRIMARY KEY (id);


--
-- Name: jurisdiction_boundary_update_runs jurisdiction_boundary_update_runs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.jurisdiction_boundary_update_runs
    ADD CONSTRAINT jurisdiction_boundary_update_runs_pkey PRIMARY KEY (id);


--
-- Name: jurisdiction_geometry_coverage jurisdiction_geometry_coverage_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.jurisdiction_geometry_coverage
    ADD CONSTRAINT jurisdiction_geometry_coverage_pkey PRIMARY KEY (state_code, unit_type);


--
-- Name: jurisdiction_unit_boundaries jurisdiction_unit_boundaries_jurisdiction_unit_id_effective_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.jurisdiction_unit_boundaries
    ADD CONSTRAINT jurisdiction_unit_boundaries_jurisdiction_unit_id_effective_key UNIQUE (jurisdiction_unit_id, effective_from);


--
-- Name: jurisdiction_unit_boundaries jurisdiction_unit_boundaries_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.jurisdiction_unit_boundaries
    ADD CONSTRAINT jurisdiction_unit_boundaries_pkey PRIMARY KEY (id);


--
-- Name: jurisdiction_units jurisdiction_units_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.jurisdiction_units
    ADD CONSTRAINT jurisdiction_units_pkey PRIMARY KEY (id);


--
-- Name: jurisdiction_units jurisdiction_units_state_code_unit_type_name_parent_id_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.jurisdiction_units
    ADD CONSTRAINT jurisdiction_units_state_code_unit_type_name_parent_id_key UNIQUE (state_code, unit_type, name, parent_id);


--
-- Name: justice_courts justice_courts_court_id_precinct_id_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.justice_courts
    ADD CONSTRAINT justice_courts_court_id_precinct_id_key UNIQUE (court_id, precinct_id);


--
-- Name: justice_courts justice_courts_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.justice_courts
    ADD CONSTRAINT justice_courts_pkey PRIMARY KEY (id);


--
-- Name: justice_precincts justice_precincts_county_id_name_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.justice_precincts
    ADD CONSTRAINT justice_precincts_county_id_name_key UNIQUE (county_id, name);


--
-- Name: justice_precincts justice_precincts_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.justice_precincts
    ADD CONSTRAINT justice_precincts_pkey PRIMARY KEY (id);


--
-- Name: jwt_revocations jwt_revocations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.jwt_revocations
    ADD CONSTRAINT jwt_revocations_pkey PRIMARY KEY (id);


--
-- Name: jwt_revocations jwt_revocations_tenant_id_jti_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.jwt_revocations
    ADD CONSTRAINT jwt_revocations_tenant_id_jti_key UNIQUE (tenant_id, jti);


--
-- Name: legal_action_catalog legal_action_catalog_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.legal_action_catalog
    ADD CONSTRAINT legal_action_catalog_pkey PRIMARY KEY (id);


--
-- Name: legal_action_catalog legal_action_catalog_state_id_action_key_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.legal_action_catalog
    ADD CONSTRAINT legal_action_catalog_state_id_action_key_key UNIQUE (state_id, action_key);


--
-- Name: legal_action_delivery_rules legal_action_delivery_rules_legal_action_id_delivery_method_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.legal_action_delivery_rules
    ADD CONSTRAINT legal_action_delivery_rules_legal_action_id_delivery_method_key UNIQUE (legal_action_id, delivery_method_id, stage);


--
-- Name: legal_action_delivery_rules legal_action_delivery_rules_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.legal_action_delivery_rules
    ADD CONSTRAINT legal_action_delivery_rules_pkey PRIMARY KEY (id);


--
-- Name: legal_action_events legal_action_events_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.legal_action_events
    ADD CONSTRAINT legal_action_events_pkey PRIMARY KEY (id);


--
-- Name: legal_action_prerequisites legal_action_prerequisites_legal_action_id_prerequisite_typ_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.legal_action_prerequisites
    ADD CONSTRAINT legal_action_prerequisites_legal_action_id_prerequisite_typ_key UNIQUE (legal_action_id, prerequisite_type);


--
-- Name: legal_action_prerequisites legal_action_prerequisites_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.legal_action_prerequisites
    ADD CONSTRAINT legal_action_prerequisites_pkey PRIMARY KEY (id);


--
-- Name: legal_action_request_targets legal_action_request_targets_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.legal_action_request_targets
    ADD CONSTRAINT legal_action_request_targets_pkey PRIMARY KEY (id);


--
-- Name: legal_action_step_requirements legal_action_step_requirements_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.legal_action_step_requirements
    ADD CONSTRAINT legal_action_step_requirements_pkey PRIMARY KEY (id);


--
-- Name: legal_action_step_requirements legal_action_step_requirements_step_id_requirement_key_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.legal_action_step_requirements
    ADD CONSTRAINT legal_action_step_requirements_step_id_requirement_key_key UNIQUE (step_id, requirement_key);


--
-- Name: legal_action_steps legal_action_steps_legal_action_id_step_key_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.legal_action_steps
    ADD CONSTRAINT legal_action_steps_legal_action_id_step_key_key UNIQUE (legal_action_id, step_key);


--
-- Name: legal_action_steps legal_action_steps_legal_action_id_step_order_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.legal_action_steps
    ADD CONSTRAINT legal_action_steps_legal_action_id_step_order_key UNIQUE (legal_action_id, step_order);


--
-- Name: legal_action_steps legal_action_steps_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.legal_action_steps
    ADD CONSTRAINT legal_action_steps_pkey PRIMARY KEY (id);


--
-- Name: legal_action_title_gates legal_action_title_gates_legal_action_id_required_event_typ_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.legal_action_title_gates
    ADD CONSTRAINT legal_action_title_gates_legal_action_id_required_event_typ_key UNIQUE (legal_action_id, required_event_type);


--
-- Name: legal_action_title_gates legal_action_title_gates_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.legal_action_title_gates
    ADD CONSTRAINT legal_action_title_gates_pkey PRIMARY KEY (id);


--
-- Name: legal_action_venue_rules legal_action_venue_rules_legal_action_id_filing_stage_count_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.legal_action_venue_rules
    ADD CONSTRAINT legal_action_venue_rules_legal_action_id_filing_stage_count_key UNIQUE (legal_action_id, filing_stage, county_id, court_type);


--
-- Name: legal_action_venue_rules legal_action_venue_rules_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.legal_action_venue_rules
    ADD CONSTRAINT legal_action_venue_rules_pkey PRIMARY KEY (id);


--
-- Name: legal_actions legal_actions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.legal_actions
    ADD CONSTRAINT legal_actions_pkey PRIMARY KEY (id);


--
-- Name: legal_holds legal_holds_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.legal_holds
    ADD CONSTRAINT legal_holds_pkey PRIMARY KEY (id);


--
-- Name: legal_phases legal_phases_phase_key_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.legal_phases
    ADD CONSTRAINT legal_phases_phase_key_key UNIQUE (phase_key);


--
-- Name: legal_phases legal_phases_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.legal_phases
    ADD CONSTRAINT legal_phases_pkey PRIMARY KEY (id);


--
-- Name: lifecycle_events lifecycle_events_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.lifecycle_events
    ADD CONSTRAINT lifecycle_events_pkey PRIMARY KEY (id);


--
-- Name: outbound_messages outbound_messages_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.outbound_messages
    ADD CONSTRAINT outbound_messages_pkey PRIMARY KEY (id);


--
-- Name: phase_document_expectations phase_document_expectations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.phase_document_expectations
    ADD CONSTRAINT phase_document_expectations_pkey PRIMARY KEY (id);


--
-- Name: phase_transitions phase_transitions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.phase_transitions
    ADD CONSTRAINT phase_transitions_pkey PRIMARY KEY (id);


--
-- Name: procedural_action_requirements procedural_action_requirement_phase_key_action_type_jurisdi_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.procedural_action_requirements
    ADD CONSTRAINT procedural_action_requirement_phase_key_action_type_jurisdi_key UNIQUE (phase_key, action_type, jurisdiction_state, jurisdiction_county, court_type);


--
-- Name: procedural_action_requirements procedural_action_requirements_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.procedural_action_requirements
    ADD CONSTRAINT procedural_action_requirements_pkey PRIMARY KEY (id);


--
-- Name: procedural_deadline_templates procedural_deadline_templates_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.procedural_deadline_templates
    ADD CONSTRAINT procedural_deadline_templates_pkey PRIMARY KEY (id);


--
-- Name: procedural_phase_actions procedural_phase_actions_phase_key_action_type_jurisdiction_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.procedural_phase_actions
    ADD CONSTRAINT procedural_phase_actions_phase_key_action_type_jurisdiction_key UNIQUE (phase_key, action_type, jurisdiction_state, jurisdiction_county, court_type);


--
-- Name: procedural_phase_actions procedural_phase_actions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.procedural_phase_actions
    ADD CONSTRAINT procedural_phase_actions_pkey PRIMARY KEY (id);


--
-- Name: process_service_event_evidence process_service_event_evidence_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.process_service_event_evidence
    ADD CONSTRAINT process_service_event_evidence_pkey PRIMARY KEY (id);


--
-- Name: process_service_events process_service_events_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.process_service_events
    ADD CONSTRAINT process_service_events_pkey PRIMARY KEY (id);


--
-- Name: properties properties_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.properties
    ADD CONSTRAINT properties_pkey PRIMARY KEY (id);


--
-- Name: property_image_derivatives property_image_derivatives_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.property_image_derivatives
    ADD CONSTRAINT property_image_derivatives_pkey PRIMARY KEY (id);


--
-- Name: property_images property_images_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.property_images
    ADD CONSTRAINT property_images_pkey PRIMARY KEY (id);


--
-- Name: property_jurisdiction_assignments property_jurisdiction_assignments_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.property_jurisdiction_assignments
    ADD CONSTRAINT property_jurisdiction_assignments_pkey PRIMARY KEY (id);


--
-- Name: property_relationships property_relationships_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.property_relationships
    ADD CONSTRAINT property_relationships_pkey PRIMARY KEY (id);


--
-- Name: property_supersession_events property_supersession_events_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.property_supersession_events
    ADD CONSTRAINT property_supersession_events_pkey PRIMARY KEY (id);


--
-- Name: purchaser_entities purchaser_entities_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.purchaser_entities
    ADD CONSTRAINT purchaser_entities_pkey PRIMARY KEY (id);


--
-- Name: role_scopes role_scopes_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.role_scopes
    ADD CONSTRAINT role_scopes_pkey PRIMARY KEY (id);


--
-- Name: role_scopes role_scopes_tenant_id_role_name_scope_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.role_scopes
    ADD CONSTRAINT role_scopes_tenant_id_role_name_scope_key UNIQUE (tenant_id, role_name, scope);


--
-- Name: roles roles_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.roles
    ADD CONSTRAINT roles_pkey PRIMARY KEY (id);


--
-- Name: secure_access_links secure_access_links_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.secure_access_links
    ADD CONSTRAINT secure_access_links_pkey PRIMARY KEY (id);


--
-- Name: service_executions service_executions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.service_executions
    ADD CONSTRAINT service_executions_pkey PRIMARY KEY (id);


--
-- Name: service_tasks service_tasks_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.service_tasks
    ADD CONSTRAINT service_tasks_pkey PRIMARY KEY (id);


--
-- Name: staging_counties staging_counties_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.staging_counties
    ADD CONSTRAINT staging_counties_pkey PRIMARY KEY (ogc_fid);


--
-- Name: staging_maricopa_precincts staging_maricopa_precincts_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.staging_maricopa_precincts
    ADD CONSTRAINT staging_maricopa_precincts_pkey PRIMARY KEY (ogc_fid);


--
-- Name: states states_code_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.states
    ADD CONSTRAINT states_code_key UNIQUE (code);


--
-- Name: states states_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.states
    ADD CONSTRAINT states_pkey PRIMARY KEY (id);


--
-- Name: tenant_client_profiles tenant_client_profiles_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tenant_client_profiles
    ADD CONSTRAINT tenant_client_profiles_pkey PRIMARY KEY (tenant_id, client_actor_id, starts_at);


--
-- Name: tenant_event_type_permissions tenant_event_type_permissions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tenant_event_type_permissions
    ADD CONSTRAINT tenant_event_type_permissions_pkey PRIMARY KEY (id);


--
-- Name: tenant_event_type_permissions tenant_event_type_permissions_tenant_id_legal_kind_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tenant_event_type_permissions
    ADD CONSTRAINT tenant_event_type_permissions_tenant_id_legal_kind_key UNIQUE (tenant_id, legal_kind);


--
-- Name: tenant_invitations tenant_invitations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tenant_invitations
    ADD CONSTRAINT tenant_invitations_pkey PRIMARY KEY (id);


--
-- Name: tenant_phase_permissions tenant_phase_permissions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tenant_phase_permissions
    ADD CONSTRAINT tenant_phase_permissions_pkey PRIMARY KEY (id);


--
-- Name: tenant_phase_permissions tenant_phase_permissions_tenant_id_phase_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tenant_phase_permissions
    ADD CONSTRAINT tenant_phase_permissions_tenant_id_phase_key UNIQUE (tenant_id, phase);


--
-- Name: tenant_properties tenant_properties_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tenant_properties
    ADD CONSTRAINT tenant_properties_pkey PRIMARY KEY (id);


--
-- Name: tenant_properties tenant_properties_tenant_property_uq; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tenant_properties
    ADD CONSTRAINT tenant_properties_tenant_property_uq UNIQUE (tenant_id, property_id);


--
-- Name: tenant_timeline_policies tenant_timeline_policies_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tenant_timeline_policies
    ADD CONSTRAINT tenant_timeline_policies_pkey PRIMARY KEY (id);


--
-- Name: tenant_timeline_policies tenant_timeline_policies_tenant_id_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tenant_timeline_policies
    ADD CONSTRAINT tenant_timeline_policies_tenant_id_key UNIQUE (tenant_id);


--
-- Name: tenants tenants_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tenants
    ADD CONSTRAINT tenants_pkey PRIMARY KEY (id);


--
-- Name: tenants tenants_slug_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tenants
    ADD CONSTRAINT tenants_slug_key UNIQUE (slug);


--
-- Name: title_events title_events_case_id_event_type_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.title_events
    ADD CONSTRAINT title_events_case_id_event_type_key UNIQUE (case_id, event_type);


--
-- Name: title_events title_events_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.title_events
    ADD CONSTRAINT title_events_pkey PRIMARY KEY (id);


--
-- Name: trustee_deed_events trustee_deed_events_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.trustee_deed_events
    ADD CONSTRAINT trustee_deed_events_pkey PRIMARY KEY (id);


--
-- Name: trustee_deed_events trustee_deed_events_trustee_sale_id_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.trustee_deed_events
    ADD CONSTRAINT trustee_deed_events_trustee_sale_id_key UNIQUE (trustee_sale_id);


--
-- Name: trustee_sale_events trustee_sale_events_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.trustee_sale_events
    ADD CONSTRAINT trustee_sale_events_pkey PRIMARY KEY (id);


--
-- Name: trustee_sale_events trustee_sale_events_property_id_auction_date_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.trustee_sale_events
    ADD CONSTRAINT trustee_sale_events_property_id_auction_date_key UNIQUE (property_id, auction_date);


--
-- Name: case_events uq_case_events_hash; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.case_events
    ADD CONSTRAINT uq_case_events_hash UNIQUE (tenant_id, case_id, event_hash);


--
-- Name: case_events uq_case_events_hash_chain; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.case_events
    ADD CONSTRAINT uq_case_events_hash_chain UNIQUE (tenant_id, case_id, previous_event_hash);


--
-- Name: case_events uq_case_sequence; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.case_events
    ADD CONSTRAINT uq_case_sequence UNIQUE (tenant_id, case_id, case_sequence);


--
-- Name: lifecycle_events uq_lifecycle_event_once; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.lifecycle_events
    ADD CONSTRAINT uq_lifecycle_event_once UNIQUE (entity_type, entity_id, event_type);


--
-- Name: procedural_deadline_templates uq_pdt; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.procedural_deadline_templates
    ADD CONSTRAINT uq_pdt UNIQUE (state_code, legal_action_key, trigger_phase_key, obligation_key);


--
-- Name: property_relationships uq_property_actor_role_active; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.property_relationships
    ADD CONSTRAINT uq_property_actor_role_active UNIQUE (tenant_id, property_id, actor_id, role, starts_at);


--
-- Name: user_credentials user_credentials_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_credentials
    ADD CONSTRAINT user_credentials_pkey PRIMARY KEY (user_id);


--
-- Name: user_roles user_roles_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_roles
    ADD CONSTRAINT user_roles_pkey PRIMARY KEY (id);


--
-- Name: users users_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_pkey PRIMARY KEY (id);


--
-- Name: users users_tenant_id_email_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_tenant_id_email_key UNIQUE (tenant_id, email);


--
-- Name: venue_resolution_audits venue_resolution_audits_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.venue_resolution_audits
    ADD CONSTRAINT venue_resolution_audits_pkey PRIMARY KEY (id);


--
-- Name: venue_rules venue_rules_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.venue_rules
    ADD CONSTRAINT venue_rules_pkey PRIMARY KEY (id);


--
-- Name: venue_rules venue_rules_state_id_legal_action_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.venue_rules
    ADD CONSTRAINT venue_rules_state_id_legal_action_key UNIQUE (state_id, legal_action);


--
-- Name: ael_case_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ael_case_idx ON public.artifact_exhibit_links USING btree (tenant_id, case_id);


--
-- Name: ael_document_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ael_document_idx ON public.artifact_exhibit_links USING btree (tenant_id, source_case_document_id);


--
-- Name: ael_lookup_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ael_lookup_idx ON public.artifact_exhibit_links USING btree (tenant_id, legal_action_id, artifact_type, intent_hash);


--
-- Name: ael_unique_key; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX ael_unique_key ON public.artifact_exhibit_links USING btree (tenant_id, legal_action_id, artifact_type, intent_hash, exhibit_key);


--
-- Name: ael_unique_label; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX ael_unique_label ON public.artifact_exhibit_links USING btree (tenant_id, legal_action_id, artifact_type, intent_hash, exhibit_label);


--
-- Name: ael_unique_sort; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX ael_unique_sort ON public.artifact_exhibit_links USING btree (tenant_id, legal_action_id, artifact_type, intent_hash, sort_order);


--
-- Name: case_capability_grants_case_id_grantee_actor_id_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX case_capability_grants_case_id_grantee_actor_id_idx ON public.case_capability_grants USING btree (case_id, grantee_actor_id) WHERE (revoked_at IS NULL);


--
-- Name: case_documents_case_id_status_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX case_documents_case_id_status_idx ON public.case_documents USING btree (case_id, status);


--
-- Name: case_events_hash_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX case_events_hash_unique ON public.case_events USING btree (tenant_id, case_id, event_hash);


--
-- Name: case_ingress_submissions_case_id_status_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX case_ingress_submissions_case_id_status_idx ON public.case_ingress_submissions USING btree (case_id, status);


--
-- Name: cases_court_case_number_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX cases_court_case_number_unique ON public.cases USING btree (court_id, case_number) WHERE ((court_id IS NOT NULL) AND (case_number IS NOT NULL));


--
-- Name: demand_delivery_events_action_target_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX demand_delivery_events_action_target_idx ON public.demand_delivery_events USING btree (tenant_id, action_request_id, target_id, created_at DESC);


--
-- Name: document_capability_grants_doc_actor_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX document_capability_grants_doc_actor_idx ON public.document_capability_grants USING btree (document_id, grantee_actor_id) WHERE (revoked_at IS NULL);


--
-- Name: document_events_document_id_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX document_events_document_id_idx ON public.document_events USING btree (document_id, changed_at);


--
-- Name: document_exports_tenant_id_case_document_id_issued_at_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX document_exports_tenant_id_case_document_id_issued_at_idx ON public.document_exports USING btree (tenant_id, case_document_id, issued_at DESC);


--
-- Name: document_exports_tenant_id_case_document_id_issued_at_idx1; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX document_exports_tenant_id_case_document_id_issued_at_idx1 ON public.document_exports USING btree (tenant_id, case_document_id, issued_at DESC);


--
-- Name: document_exports_tenant_id_id_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX document_exports_tenant_id_id_idx ON public.document_exports USING btree (tenant_id, id);


--
-- Name: document_exports_tenant_id_id_idx1; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX document_exports_tenant_id_id_idx1 ON public.document_exports USING btree (tenant_id, id);


--
-- Name: document_versions_document_version_uq; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX document_versions_document_version_uq ON public.document_versions USING btree (tenant_id, document_id, version_number);


--
-- Name: filing_bundles_hash_unique_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX filing_bundles_hash_unique_idx ON public.filing_bundles USING btree (tenant_id, case_id, legal_action_id, bundle_hash) WHERE (bundle_hash IS NOT NULL);


--
-- Name: filing_documents_bundle_lookup; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX filing_documents_bundle_lookup ON public.filing_documents USING btree (tenant_id, bundle_id, document_type);


--
-- Name: idx_action_doc_expectations_lookup; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_action_doc_expectations_lookup ON public.action_document_expectations USING btree (state_code, legal_action_key, requested_action, phase_key);


--
-- Name: idx_ade_lookup; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_ade_lookup ON public.action_document_expectations USING btree (state_code, legal_action_key, requested_action) WHERE (tenant_id IS NULL);


--
-- Name: idx_attorney_filing_profiles_actor; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_attorney_filing_profiles_actor ON public.attorney_filing_profiles USING btree (tenant_id, actor_id, effective_from, effective_to);


--
-- Name: idx_audit_events_actor; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_audit_events_actor ON public.audit_events USING btree (actor_id);


--
-- Name: idx_auth_access_token_revocations_tenant; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_auth_access_token_revocations_tenant ON public.auth_access_token_revocations USING btree (tenant_id);


--
-- Name: idx_case_deadlines_case_due; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_case_deadlines_case_due ON public.case_deadlines USING btree (case_id, due_at);


--
-- Name: idx_case_documents_source_action_request; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_case_documents_source_action_request ON public.case_documents USING btree (tenant_id, case_id, source_action_request_id);


--
-- Name: idx_case_events_case_time; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_case_events_case_time ON public.case_events USING btree (case_id, occurred_at);


--
-- Name: idx_case_events_case_timeline; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_case_events_case_timeline ON public.case_events USING btree (tenant_id, case_id, case_sequence) INCLUDE (event_type, occurred_at, actor_id, payload, created_at);


--
-- Name: idx_case_events_causation; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_case_events_causation ON public.case_events USING btree (causation_id);


--
-- Name: idx_case_events_correlation; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_case_events_correlation ON public.case_events USING btree (correlation_id);


--
-- Name: idx_case_events_executed; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_case_events_executed ON public.case_events USING btree (tenant_id, event_type, executed_at);


--
-- Name: idx_case_events_foreclosure_sale; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_case_events_foreclosure_sale ON public.case_events USING btree (case_id) WHERE (event_type = 'foreclosure_sale'::text);


--
-- Name: idx_case_events_trustee_deed; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_case_events_trustee_deed ON public.case_events USING btree (case_id) WHERE (event_type = 'trustee_deed_recorded'::text);


--
-- Name: idx_case_parties_case; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_case_parties_case ON public.case_parties USING btree (case_id);


--
-- Name: idx_case_parties_unique_actor_role_active; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX idx_case_parties_unique_actor_role_active ON public.case_parties USING btree (tenant_id, case_id, actor_id, party_role) WHERE (ends_at IS NULL);


--
-- Name: idx_case_proc_snapshot_lookup; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_case_proc_snapshot_lookup ON public.case_procedure_snapshots USING btree (tenant_id, case_id, procedure_name, snapshot_sequence DESC);


--
-- Name: idx_case_venue_overrides_case; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_case_venue_overrides_case ON public.case_venue_overrides USING btree (tenant_id, case_id);


--
-- Name: idx_cases_filing_committed; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_cases_filing_committed ON public.cases USING btree (tenant_id, id) WHERE (filing_committed_at IS NOT NULL);


--
-- Name: idx_counties_state_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_counties_state_id ON public.counties USING btree (state_id);


--
-- Name: idx_court_filing_locations_active_lookup; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_court_filing_locations_active_lookup ON public.court_filing_locations USING btree (court_id, accepts_filings, is_primary DESC, sort_order, location_name);


--
-- Name: idx_court_filing_locations_court; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_court_filing_locations_court ON public.court_filing_locations USING btree (court_id, effective_from, effective_to);


--
-- Name: idx_courts_county_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_courts_county_id ON public.courts USING btree (county_id);


--
-- Name: idx_courts_state_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_courts_state_id ON public.courts USING btree (state_id);


--
-- Name: idx_courts_type; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_courts_type ON public.courts USING btree (court_type);


--
-- Name: idx_courts_type_code; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_courts_type_code ON public.courts USING btree (court_type_code);


--
-- Name: idx_cpc_case; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_cpc_case ON public.case_prerequisite_compliance USING btree (case_id);


--
-- Name: idx_cpc_phase_action; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_cpc_phase_action ON public.case_prerequisite_compliance USING btree (phase_key, action_type);


--
-- Name: idx_cpc_tenant_case; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_cpc_tenant_case ON public.case_prerequisite_compliance USING btree (tenant_id, case_id);


--
-- Name: idx_cpce_compliance; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_cpce_compliance ON public.case_prerequisite_compliance_evidence USING btree (compliance_id);


--
-- Name: idx_cpce_tenant; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_cpce_tenant ON public.case_prerequisite_compliance_evidence USING btree (tenant_id);


--
-- Name: idx_cpd_case_due; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_cpd_case_due ON public.case_procedural_deadlines USING btree (case_id, due_at);


--
-- Name: idx_cpd_case_obligation; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_cpd_case_obligation ON public.case_procedural_deadlines USING btree (case_id, obligation_key);


--
-- Name: idx_cpd_case_phase; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_cpd_case_phase ON public.case_procedural_deadlines USING btree (case_id, phase_key);


--
-- Name: idx_cron_job_runs_job_started; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_cron_job_runs_job_started ON public.cron_job_runs USING btree (job_name, started_at DESC);


--
-- Name: idx_cron_job_runs_status_started; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_cron_job_runs_status_started ON public.cron_job_runs USING btree (status, started_at DESC);


--
-- Name: idx_cva_case; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_cva_case ON public.case_venue_assignments USING btree (case_id);


--
-- Name: idx_cva_court; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_cva_court ON public.case_venue_assignments USING btree (court_id);


--
-- Name: idx_cva_precinct; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_cva_precinct ON public.case_venue_assignments USING btree (precinct_id);


--
-- Name: idx_cva_stage; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_cva_stage ON public.case_venue_assignments USING btree (stage);


--
-- Name: idx_dde_certmail_eligible_created; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_dde_certmail_eligible_created ON public.demand_delivery_events USING btree (created_at) WHERE ((delivery_method = 'certified_mail'::text) AND (delivery_status = 'sent'::text) AND (delivered_at IS NULL));


--
-- Name: idx_dde_certmail_eligible_tenant_created; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_dde_certmail_eligible_tenant_created ON public.demand_delivery_events USING btree (tenant_id, created_at) WHERE ((delivery_method = 'certified_mail'::text) AND (delivery_status = 'sent'::text) AND (delivered_at IS NULL));


--
-- Name: idx_dde_method_status_deliveredat_created; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_dde_method_status_deliveredat_created ON public.demand_delivery_events USING btree (delivery_method, delivery_status, delivered_at, created_at);


--
-- Name: idx_demand_delivery_case_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_demand_delivery_case_id ON public.demand_delivery_events USING btree (case_id);


--
-- Name: idx_demand_delivery_date; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_demand_delivery_date ON public.demand_delivery_events USING btree (delivered_at);


--
-- Name: idx_demand_delivery_events_claimable; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_demand_delivery_events_claimable ON public.demand_delivery_events USING btree (delivery_method, delivery_status, cron_claimed_at) WHERE ((delivery_method = 'certified_mail'::text) AND (delivery_status = 'sent'::text));


--
-- Name: idx_demand_delivery_method; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_demand_delivery_method ON public.demand_delivery_events USING btree (delivery_method);


--
-- Name: idx_demand_delivery_request_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_demand_delivery_request_id ON public.demand_delivery_events USING btree (action_request_id);


--
-- Name: idx_documents_canonical_tags; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_documents_canonical_tags ON public.documents USING gin (canonical_tags);


--
-- Name: idx_documents_freeform_tags; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_documents_freeform_tags ON public.documents USING gin (freeform_tags);


--
-- Name: idx_event_replay_audit_tenant_case_started; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_event_replay_audit_tenant_case_started ON public.event_replay_audit USING btree (tenant_id, case_id, started_at DESC);


--
-- Name: idx_evidence_access_grants_export; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_evidence_access_grants_export ON public.evidence_access_grants USING btree (tenant_id, document_export_id);


--
-- Name: idx_evidence_access_grants_tenant; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_evidence_access_grants_tenant ON public.evidence_access_grants USING btree (tenant_id);


--
-- Name: idx_filing_bundles_bundle_hash; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_filing_bundles_bundle_hash ON public.filing_bundles USING btree (bundle_hash);


--
-- Name: idx_filing_bundles_case_frozen; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_filing_bundles_case_frozen ON public.filing_bundles USING btree (tenant_id, case_id) WHERE (frozen = true);


--
-- Name: idx_filing_bundles_cursor; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_filing_bundles_cursor ON public.filing_bundles USING btree (tenant_id, case_id, event_cursor);


--
-- Name: idx_filing_bundles_lookup_prepared; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_filing_bundles_lookup_prepared ON public.filing_bundles USING btree (tenant_id, case_id, legal_action_id) WHERE (freeze_level = 'prepared'::text);


--
-- Name: idx_filing_bundles_prepared; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_filing_bundles_prepared ON public.filing_bundles USING btree (tenant_id, case_id, legal_action_id) WHERE (freeze_level = 'prepared'::text);


--
-- Name: idx_jbd_state_unit_created; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_jbd_state_unit_created ON public.jurisdiction_boundary_datasets USING btree (state_code, unit_type, created_at DESC);


--
-- Name: idx_jbir_dataset_started; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_jbir_dataset_started ON public.jurisdiction_boundary_ingest_runs USING btree (dataset_id, started_at DESC);


--
-- Name: idx_jub_boundary_geom; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_jub_boundary_geom ON public.jurisdiction_unit_boundaries USING gist (boundary_geometry);


--
-- Name: idx_jub_boundary_gist; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_jub_boundary_gist ON public.jurisdiction_unit_boundaries USING gist (boundary_geometry);


--
-- Name: idx_jub_temporal_lookup; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_jub_temporal_lookup ON public.jurisdiction_unit_boundaries USING btree (jurisdiction_unit_id, effective_from, effective_to);


--
-- Name: idx_jub_unit_current; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_jub_unit_current ON public.jurisdiction_unit_boundaries USING btree (jurisdiction_unit_id) WHERE (effective_to IS NULL);


--
-- Name: idx_jurisdiction_units_boundary_gist; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_jurisdiction_units_boundary_gist ON public.jurisdiction_units USING gist (boundary_geometry);


--
-- Name: idx_justice_courts_court_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_justice_courts_court_id ON public.justice_courts USING btree (court_id);


--
-- Name: idx_justice_courts_precinct_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_justice_courts_precinct_id ON public.justice_courts USING btree (precinct_id);


--
-- Name: idx_justice_precincts_county_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_justice_precincts_county_id ON public.justice_precincts USING btree (county_id);


--
-- Name: idx_justice_precincts_state_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_justice_precincts_state_id ON public.justice_precincts USING btree (state_id);


--
-- Name: idx_ladr_action_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_ladr_action_id ON public.legal_action_delivery_rules USING btree (legal_action_id);


--
-- Name: idx_ladr_method; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_ladr_method ON public.legal_action_delivery_rules USING btree (delivery_method_id);


--
-- Name: idx_ladr_stage; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_ladr_stage ON public.legal_action_delivery_rules USING btree (stage);


--
-- Name: idx_lap_action_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_lap_action_id ON public.legal_action_prerequisites USING btree (legal_action_id);


--
-- Name: idx_lap_blocking_phase; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_lap_blocking_phase ON public.legal_action_prerequisites USING btree (blocking_phase);


--
-- Name: idx_latg_action; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_latg_action ON public.legal_action_title_gates USING btree (legal_action_id);


--
-- Name: idx_latg_stage; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_latg_stage ON public.legal_action_title_gates USING btree (blocking_stage);


--
-- Name: idx_lavr_action; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_lavr_action ON public.legal_action_venue_rules USING btree (legal_action_id);


--
-- Name: idx_lavr_county; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_lavr_county ON public.legal_action_venue_rules USING btree (county_id);


--
-- Name: idx_lavr_court_type; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_lavr_court_type ON public.legal_action_venue_rules USING btree (court_type);


--
-- Name: idx_lavr_stage; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_lavr_stage ON public.legal_action_venue_rules USING btree (filing_stage);


--
-- Name: idx_lavr_state; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_lavr_state ON public.legal_action_venue_rules USING btree (state_code);


--
-- Name: idx_legal_action_catalog_action_key; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_legal_action_catalog_action_key ON public.legal_action_catalog USING btree (action_key);


--
-- Name: idx_legal_action_catalog_phase; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_legal_action_catalog_phase ON public.legal_action_catalog USING btree (phase_key);


--
-- Name: idx_legal_action_catalog_state; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_legal_action_catalog_state ON public.legal_action_catalog USING btree (state_id);


--
-- Name: idx_legal_action_catalog_state_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_legal_action_catalog_state_id ON public.legal_action_catalog USING btree (state_id);


--
-- Name: idx_legal_action_steps_action; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_legal_action_steps_action ON public.legal_action_steps USING btree (legal_action_id);


--
-- Name: idx_legal_action_steps_phase; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_legal_action_steps_phase ON public.legal_action_steps USING btree (phase);


--
-- Name: idx_legal_actions_filing_attorney; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_legal_actions_filing_attorney ON public.legal_actions USING btree (tenant_id, filing_attorney_actor_id);


--
-- Name: idx_legal_holds_active; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_legal_holds_active ON public.legal_holds USING btree (tenant_id, scope_type, scope_id) WHERE (released_at IS NULL);


--
-- Name: idx_owner_adjudicated; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_owner_adjudicated ON public.property_relationships USING btree (tenant_id, property_id) WHERE ((role = 'owner'::public.party_role) AND (ownership_status = 'adjudicated'::text) AND (ends_at IS NULL));


--
-- Name: idx_par_phase_action; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_par_phase_action ON public.procedural_action_requirements USING btree (phase_key, action_type);


--
-- Name: idx_pdt_lookup; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_pdt_lookup ON public.procedural_deadline_templates USING btree (state_code, legal_action_key, trigger_phase_key);


--
-- Name: idx_phase_doc_expectations_lookup; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_phase_doc_expectations_lookup ON public.phase_document_expectations USING btree (state_code, legal_action_key, phase_key) WHERE (is_active = true);


--
-- Name: idx_phase_transitions_lookup; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_phase_transitions_lookup ON public.phase_transitions USING btree (state_code, legal_action_key, from_phase_key, on_event_type) WHERE (is_active = true);


--
-- Name: idx_phase_transitions_tenant; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_phase_transitions_tenant ON public.phase_transitions USING btree (tenant_id) WHERE (tenant_id IS NOT NULL);


--
-- Name: idx_ppa_action; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_ppa_action ON public.procedural_phase_actions USING btree (action_type);


--
-- Name: idx_ppa_phase; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_ppa_phase ON public.procedural_phase_actions USING btree (phase_key);


--
-- Name: idx_precincts_county; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_precincts_county ON public.court_precincts USING btree (county_id);


--
-- Name: idx_precincts_court; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_precincts_court ON public.court_precincts USING btree (court_id);


--
-- Name: idx_process_service_events_affidavit; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_process_service_events_affidavit ON public.process_service_events USING btree (affidavit_document_id);


--
-- Name: idx_process_service_events_case; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_process_service_events_case ON public.process_service_events USING btree (tenant_id, case_id);


--
-- Name: idx_process_service_events_property; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_process_service_events_property ON public.process_service_events USING btree (tenant_id, property_id);


--
-- Name: idx_property_image_derivatives_image_active; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_property_image_derivatives_image_active ON public.property_image_derivatives USING btree (image_id) WHERE (deleted_at IS NULL);


--
-- Name: idx_property_image_derivatives_tenant; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_property_image_derivatives_tenant ON public.property_image_derivatives USING btree (tenant_id);


--
-- Name: idx_property_image_derivatives_variant; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_property_image_derivatives_variant ON public.property_image_derivatives USING btree (image_id, variant) WHERE (deleted_at IS NULL);


--
-- Name: idx_property_images_hash; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_property_images_hash ON public.property_images USING btree (content_hash);


--
-- Name: idx_property_images_property; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_property_images_property ON public.property_images USING btree (property_id);


--
-- Name: idx_property_images_property_active; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_property_images_property_active ON public.property_images USING btree (property_id) WHERE (deleted_at IS NULL);


--
-- Name: idx_property_images_tenant; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_property_images_tenant ON public.property_images USING btree (tenant_id);


--
-- Name: idx_property_relationships_actor; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_property_relationships_actor ON public.property_relationships USING btree (tenant_id, actor_id);


--
-- Name: idx_property_relationships_property_active; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_property_relationships_property_active ON public.property_relationships USING btree (tenant_id, property_id) WHERE (ends_at IS NULL);


--
-- Name: idx_property_relationships_role_active; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_property_relationships_role_active ON public.property_relationships USING btree (tenant_id, property_id, role) WHERE (ends_at IS NULL);


--
-- Name: idx_purchaser_entities_owner; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_purchaser_entities_owner ON public.purchaser_entities USING btree (owner_actor_id);


--
-- Name: idx_purchaser_entities_tenant; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_purchaser_entities_tenant ON public.purchaser_entities USING btree (tenant_id);


--
-- Name: idx_step_requirements_step_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_step_requirements_step_id ON public.legal_action_step_requirements USING btree (step_id);


--
-- Name: idx_step_requirements_type; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_step_requirements_type ON public.legal_action_step_requirements USING btree (requirement_type);


--
-- Name: idx_tenant_invitations_lookup; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_tenant_invitations_lookup ON public.tenant_invitations USING btree (tenant_id, token_hash);


--
-- Name: idx_title_events_case; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_title_events_case ON public.title_events USING btree (case_id);


--
-- Name: idx_title_events_county; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_title_events_county ON public.title_events USING btree (county_id);


--
-- Name: idx_title_events_entity; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_title_events_entity ON public.title_events USING btree (purchaser_entity_id);


--
-- Name: idx_title_events_property; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_title_events_property ON public.title_events USING btree (property_id);


--
-- Name: idx_trustee_deed_case; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_trustee_deed_case ON public.trustee_deed_events USING btree (case_id);


--
-- Name: idx_trustee_deed_property; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_trustee_deed_property ON public.trustee_deed_events USING btree (property_id);


--
-- Name: idx_trustee_deed_sale; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_trustee_deed_sale ON public.trustee_deed_events USING btree (trustee_sale_id);


--
-- Name: idx_trustee_sale_case; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_trustee_sale_case ON public.trustee_sale_events USING btree (case_id);


--
-- Name: idx_trustee_sale_property; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_trustee_sale_property ON public.trustee_sale_events USING btree (property_id);


--
-- Name: idx_trustee_sale_purchaser; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_trustee_sale_purchaser ON public.trustee_sale_events USING btree (purchaser_entity_id);


--
-- Name: idx_user_credentials_user_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_user_credentials_user_id ON public.user_credentials USING btree (user_id);


--
-- Name: idx_venue_rules_legal_action; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_venue_rules_legal_action ON public.venue_rules USING btree (legal_action);


--
-- Name: idx_venue_rules_state_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_venue_rules_state_id ON public.venue_rules USING btree (state_id);


--
-- Name: idx_vra_boundary_hash; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_vra_boundary_hash ON public.venue_resolution_audits USING btree (resolved_boundary_hash);


--
-- Name: idx_vra_case_asof; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_vra_case_asof ON public.venue_resolution_audits USING btree (case_id, as_of_date);


--
-- Name: idx_vra_case_stage; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_vra_case_stage ON public.venue_resolution_audits USING btree (tenant_id, case_id, stage, created_at DESC);


--
-- Name: idx_vra_dataset; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_vra_dataset ON public.venue_resolution_audits USING btree (resolved_dataset_id);


--
-- Name: idx_vra_dataset_hash; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_vra_dataset_hash ON public.venue_resolution_audits USING btree (resolved_dataset_hash_sha256);


--
-- Name: ix_filing_bundles_case; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ix_filing_bundles_case ON public.filing_bundles USING btree (tenant_id, case_id);


--
-- Name: ix_filing_bundles_legal_action; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ix_filing_bundles_legal_action ON public.filing_bundles USING btree (tenant_id, legal_action_id);


--
-- Name: pja_one_current_per_property_type_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX pja_one_current_per_property_type_idx ON public.property_jurisdiction_assignments USING btree (property_id, jurisdiction_type) WHERE (valid_to IS NULL);


--
-- Name: pja_property_history_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX pja_property_history_idx ON public.property_jurisdiction_assignments USING btree (property_id, jurisdiction_type, valid_from DESC);


--
-- Name: pja_unit_boundary_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX pja_unit_boundary_idx ON public.property_jurisdiction_assignments USING btree (jurisdiction_unit_id, boundary_id);


--
-- Name: properties_tenant_owner_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX properties_tenant_owner_idx ON public.properties USING btree (tenant_id, owner_actor_id);


--
-- Name: staging_counties_wkb_geometry_geom_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX staging_counties_wkb_geometry_geom_idx ON public.staging_counties USING gist (wkb_geometry);


--
-- Name: staging_maricopa_precincts_wkb_geometry_geom_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX staging_maricopa_precincts_wkb_geometry_geom_idx ON public.staging_maricopa_precincts USING gist (wkb_geometry);


--
-- Name: tenant_client_profiles_tenant_id_client_actor_id_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX tenant_client_profiles_tenant_id_client_actor_id_idx ON public.tenant_client_profiles USING btree (tenant_id, client_actor_id) WHERE (ends_at IS NULL);


--
-- Name: tenant_properties_added_by_user_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX tenant_properties_added_by_user_idx ON public.tenant_properties USING btree (tenant_id, added_by_user_id) WHERE (added_by_user_id IS NOT NULL);


--
-- Name: tenant_properties_property_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX tenant_properties_property_idx ON public.tenant_properties USING btree (property_id);


--
-- Name: tenant_properties_tenant_status_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX tenant_properties_tenant_status_idx ON public.tenant_properties USING btree (tenant_id, portfolio_status);


--
-- Name: uniq_active_case_party_role; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uniq_active_case_party_role ON public.case_parties USING btree (case_id, actor_id, party_role) WHERE (ends_at IS NULL);


--
-- Name: uniq_az_demand_service_proof_per_case; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uniq_az_demand_service_proof_per_case ON public.case_procedural_deadlines USING btree (tenant_id, case_id, obligation_key) WHERE (obligation_key = 'az_demand_service_proof'::text);


--
-- Name: uniq_dde_per_target_method; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uniq_dde_per_target_method ON public.demand_delivery_events USING btree (tenant_id, case_id, action_request_id, target_id, delivery_method);


--
-- Name: uniq_legal_actions_idempotency; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uniq_legal_actions_idempotency ON public.legal_actions USING btree (tenant_id, case_id, action_type, parent_legal_action_id);


--
-- Name: uniq_one_demand_service_proof; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uniq_one_demand_service_proof ON public.case_procedural_deadlines USING btree (tenant_id, case_id, obligation_key) WHERE (obligation_key = 'az_demand_service_proof'::text);


--
-- Name: uq_attorney_filing_profiles_active; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uq_attorney_filing_profiles_active ON public.attorney_filing_profiles USING btree (tenant_id, actor_id) WHERE (effective_to IS NULL);


--
-- Name: uq_case_documents_demand_per_action; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uq_case_documents_demand_per_action ON public.case_documents USING btree (tenant_id, case_id, source_action_request_id, document_type_key) WHERE ((document_type_key = 'az_demand_for_possession'::text) AND (source_action_request_id IS NOT NULL));


--
-- Name: uq_court_filing_locations_active_primary; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uq_court_filing_locations_active_primary ON public.court_filing_locations USING btree (court_id) WHERE ((is_primary = true) AND (effective_to IS NULL));


--
-- Name: uq_foreclosure_trustee_per_loan; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uq_foreclosure_trustee_per_loan ON public.property_relationships USING btree (tenant_id, scope_id) WHERE ((role = 'foreclosure_trustee'::public.party_role) AND (scope_type = 'loan'::text) AND (ends_at IS NULL));


--
-- Name: uq_idempotency_keys; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uq_idempotency_keys ON public.idempotency_keys USING btree (tenant_id, actor_id, endpoint, idempotency_key);


--
-- Name: uq_property_relationships_no_duplicate_active; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uq_property_relationships_no_duplicate_active ON public.property_relationships USING btree (tenant_id, property_id, actor_id, role, COALESCE(scope_type, ''::text), COALESCE((scope_id)::text, ''::text)) WHERE (ends_at IS NULL);


--
-- Name: ux_action_doc_expectations; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX ux_action_doc_expectations ON public.action_document_expectations USING btree (state_code, legal_action_key, phase_key, requested_action, document_type_key);


--
-- Name: ux_active_boundary_per_unit; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX ux_active_boundary_per_unit ON public.jurisdiction_unit_boundaries USING btree (jurisdiction_unit_id) WHERE (effective_to IS NULL);


--
-- Name: ux_artifact_exhibit_links_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX ux_artifact_exhibit_links_unique ON public.artifact_exhibit_links USING btree (tenant_id, case_id, legal_action_id, artifact_type, exhibit_key);


--
-- Name: ux_case_events_case_sequence; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX ux_case_events_case_sequence ON public.case_events USING btree (tenant_id, case_id, case_sequence);


--
-- Name: ux_case_events_event_hash; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX ux_case_events_event_hash ON public.case_events USING btree (tenant_id, case_id, event_hash);


--
-- Name: ux_case_events_idempotent; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX ux_case_events_idempotent ON public.case_events USING btree (tenant_id, idempotency_key);


--
-- Name: ux_filing_bundles_one_committed_per_action; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX ux_filing_bundles_one_committed_per_action ON public.filing_bundles USING btree (tenant_id, legal_action_id) WHERE (freeze_level = 'committed'::text);


--
-- Name: ux_filing_bundles_one_prepared_per_action; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX ux_filing_bundles_one_prepared_per_action ON public.filing_bundles USING btree (tenant_id, legal_action_id) WHERE (freeze_level = 'prepared'::text);


--
-- Name: ux_phase_transitions; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX ux_phase_transitions ON public.phase_transitions USING btree (state_code, legal_action_key, from_phase_key, on_event_type);


--
-- Name: ux_purchaser_entities_name_scope; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX ux_purchaser_entities_name_scope ON public.purchaser_entities USING btree (tenant_id, owner_actor_id, lower(legal_name));


--
-- Name: audit_events audit_events_immutable; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER audit_events_immutable BEFORE DELETE OR UPDATE ON public.audit_events FOR EACH ROW EXECUTE FUNCTION public.prevent_audit_mutation();


--
-- Name: case_documents case_documents_final_lock; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER case_documents_final_lock BEFORE UPDATE OF current_version_id ON public.case_documents FOR EACH ROW WHEN ((old.status = 'final'::text)) EXECUTE FUNCTION public.prevent_finalized_version_reassignment();


--
-- Name: document_exports document_exports_immutable; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER document_exports_immutable BEFORE DELETE OR UPDATE ON public.document_exports FOR EACH ROW EXECUTE FUNCTION public.reject_mutation();


--
-- Name: filing_documents filing_packet_dependency_guard; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER filing_packet_dependency_guard BEFORE INSERT ON public.filing_documents FOR EACH ROW EXECUTE FUNCTION public.enforce_filing_packet_dependencies();


--
-- Name: demand_delivery_events freeze_guard_delivery; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER freeze_guard_delivery BEFORE INSERT OR DELETE OR UPDATE ON public.demand_delivery_events FOR EACH ROW EXECUTE FUNCTION public.freeze_guard();


--
-- Name: case_documents freeze_guard_documents; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER freeze_guard_documents BEFORE DELETE OR UPDATE ON public.case_documents FOR EACH ROW EXECUTE FUNCTION public.freeze_guard();


--
-- Name: legal_actions freeze_guard_legal_actions; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER freeze_guard_legal_actions BEFORE INSERT OR DELETE OR UPDATE ON public.legal_actions FOR EACH ROW EXECUTE FUNCTION public.freeze_guard();


--
-- Name: service_tasks freeze_guard_service_tasks; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER freeze_guard_service_tasks BEFORE INSERT OR DELETE OR UPDATE ON public.service_tasks FOR EACH ROW EXECUTE FUNCTION public.freeze_guard();


--
-- Name: case_documents guard_case_documents; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER guard_case_documents BEFORE INSERT OR DELETE OR UPDATE ON public.case_documents FOR EACH ROW EXECUTE FUNCTION public.guard_case_mutation();


--
-- Name: case_events guard_case_events; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER guard_case_events BEFORE INSERT OR DELETE OR UPDATE ON public.case_events FOR EACH ROW EXECUTE FUNCTION public.guard_case_mutation();


--
-- Name: legal_actions guard_legal_actions; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER guard_legal_actions BEFORE INSERT OR DELETE OR UPDATE ON public.legal_actions FOR EACH ROW EXECUTE FUNCTION public.guard_case_mutation('has_action');


--
-- Name: service_tasks guard_service_tasks; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER guard_service_tasks BEFORE INSERT OR DELETE OR UPDATE ON public.service_tasks FOR EACH ROW EXECUTE FUNCTION public.guard_case_mutation('has_action');


--
-- Name: artifact_exhibit_links trg_artifact_exhibit_links_frozen_guard; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_artifact_exhibit_links_frozen_guard BEFORE INSERT OR DELETE OR UPDATE ON public.artifact_exhibit_links FOR EACH ROW EXECUTE FUNCTION public.case_legal_action_frozen_guard();


--
-- Name: case_documents trg_case_documents_frozen_guard; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_case_documents_frozen_guard BEFORE INSERT OR DELETE OR UPDATE ON public.case_documents FOR EACH ROW EXECUTE FUNCTION public.case_legal_action_frozen_guard();


--
-- Name: case_documents trg_case_documents_post_submit_lock; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_case_documents_post_submit_lock BEFORE UPDATE ON public.case_documents FOR EACH ROW EXECUTE FUNCTION public.enforce_case_document_post_submit_immutability();


--
-- Name: document_versions trg_document_versions_after_submit; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_document_versions_after_submit BEFORE INSERT ON public.document_versions FOR EACH ROW EXECUTE FUNCTION public.prevent_versions_after_case_document_submit();


--
-- Name: document_versions trg_document_versions_frozen_guard; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_document_versions_frozen_guard BEFORE INSERT OR DELETE OR UPDATE ON public.document_versions FOR EACH ROW EXECUTE FUNCTION public.case_legal_action_frozen_guard();


--
-- Name: document_versions trg_document_versions_immutable; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_document_versions_immutable BEFORE UPDATE ON public.document_versions FOR EACH ROW EXECUTE FUNCTION public.enforce_final_document_immutability();


--
-- Name: courts trg_enforce_court_jurisdiction_unit_type; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_enforce_court_jurisdiction_unit_type BEFORE INSERT OR UPDATE OF court_type_code, jurisdiction_unit_id ON public.courts FOR EACH ROW EXECUTE FUNCTION public.enforce_court_jurisdiction_unit_type();


--
-- Name: demand_delivery_events trg_enforce_demand_delivery_proof; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_enforce_demand_delivery_proof BEFORE INSERT OR UPDATE ON public.demand_delivery_events FOR EACH ROW EXECUTE FUNCTION public.enforce_demand_delivery_proof();


--
-- Name: jurisdiction_units trg_enforce_jurisdiction_unit_parent_types; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_enforce_jurisdiction_unit_parent_types BEFORE INSERT OR UPDATE OF unit_type, parent_id ON public.jurisdiction_units FOR EACH ROW EXECUTE FUNCTION public.enforce_jurisdiction_unit_parent_types();


--
-- Name: filing_bundles trg_filing_bundles_no_delete; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_filing_bundles_no_delete BEFORE DELETE ON public.filing_bundles FOR EACH ROW EXECUTE FUNCTION public.filing_bundles_immutable_guard();


--
-- Name: filing_bundles trg_filing_bundles_no_update; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_filing_bundles_no_update BEFORE UPDATE ON public.filing_bundles FOR EACH ROW EXECUTE FUNCTION public.filing_bundles_immutable_guard();


--
-- Name: case_documents trg_guard_case_documents; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_guard_case_documents BEFORE DELETE OR UPDATE ON public.case_documents FOR EACH ROW EXECUTE FUNCTION public.guard_case_mutation();


--
-- Name: demand_delivery_events trg_guard_demand_delivery_events; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_guard_demand_delivery_events BEFORE INSERT OR DELETE OR UPDATE ON public.demand_delivery_events FOR EACH ROW EXECUTE FUNCTION public.guard_case_mutation();


--
-- Name: legal_actions trg_guard_legal_actions; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_guard_legal_actions BEFORE DELETE OR UPDATE ON public.legal_actions FOR EACH ROW EXECUTE FUNCTION public.guard_legal_action_mutation();


--
-- Name: service_tasks trg_guard_service_tasks; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_guard_service_tasks BEFORE INSERT OR DELETE OR UPDATE ON public.service_tasks FOR EACH ROW EXECUTE FUNCTION public.guard_case_mutation();


--
-- Name: case_events trg_verify_case_event_hash; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_verify_case_event_hash BEFORE INSERT OR UPDATE ON public.case_events FOR EACH ROW EXECUTE FUNCTION public.verify_case_event_hash();


--
-- Name: actor_identities actor_identities_actor_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.actor_identities
    ADD CONSTRAINT actor_identities_actor_id_fkey FOREIGN KEY (actor_id) REFERENCES public.actors(id);


--
-- Name: actor_identities actor_identities_created_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.actor_identities
    ADD CONSTRAINT actor_identities_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.users(id);


--
-- Name: actor_identities actor_identities_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.actor_identities
    ADD CONSTRAINT actor_identities_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id);


--
-- Name: actors actors_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.actors
    ADD CONSTRAINT actors_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id);


--
-- Name: artifact_exhibit_links ael_case_document_fk; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.artifact_exhibit_links
    ADD CONSTRAINT ael_case_document_fk FOREIGN KEY (source_case_document_id) REFERENCES public.case_documents(id) ON DELETE RESTRICT;


--
-- Name: artifact_exhibit_links ael_legal_action_fk; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.artifact_exhibit_links
    ADD CONSTRAINT ael_legal_action_fk FOREIGN KEY (legal_action_id) REFERENCES public.legal_actions(id) ON DELETE CASCADE;


--
-- Name: artifact_exhibit_links ael_version_fk; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.artifact_exhibit_links
    ADD CONSTRAINT ael_version_fk FOREIGN KEY (source_version_id) REFERENCES public.document_versions(id) ON DELETE RESTRICT;


--
-- Name: api_idempotency_keys api_idempotency_keys_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.api_idempotency_keys
    ADD CONSTRAINT api_idempotency_keys_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id);


--
-- Name: assignments assignments_assignee_actor_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.assignments
    ADD CONSTRAINT assignments_assignee_actor_id_fkey FOREIGN KEY (assignee_actor_id) REFERENCES public.actors(id);


--
-- Name: assignments assignments_case_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.assignments
    ADD CONSTRAINT assignments_case_id_fkey FOREIGN KEY (case_id) REFERENCES public.cases(id);


--
-- Name: assignments assignments_created_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.assignments
    ADD CONSTRAINT assignments_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.users(id);


--
-- Name: assignments assignments_service_execution_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.assignments
    ADD CONSTRAINT assignments_service_execution_id_fkey FOREIGN KEY (service_execution_id) REFERENCES public.service_executions(id);


--
-- Name: assignments assignments_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.assignments
    ADD CONSTRAINT assignments_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id);


--
-- Name: attorney_filing_profiles attorney_filing_profiles_actor_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.attorney_filing_profiles
    ADD CONSTRAINT attorney_filing_profiles_actor_id_fkey FOREIGN KEY (actor_id) REFERENCES public.actors(id) ON DELETE CASCADE;


--
-- Name: attorney_filing_profiles attorney_filing_profiles_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.attorney_filing_profiles
    ADD CONSTRAINT attorney_filing_profiles_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: audit_events audit_events_actor_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.audit_events
    ADD CONSTRAINT audit_events_actor_user_id_fkey FOREIGN KEY (actor_user_id) REFERENCES public.users(id);


--
-- Name: audit_events audit_events_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.audit_events
    ADD CONSTRAINT audit_events_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id);


--
-- Name: auth_access_token_revocations auth_access_token_revocations_tenant_fk; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.auth_access_token_revocations
    ADD CONSTRAINT auth_access_token_revocations_tenant_fk FOREIGN KEY (tenant_id) REFERENCES public.tenants(id);


--
-- Name: case_capability_grants case_capability_grants_case_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.case_capability_grants
    ADD CONSTRAINT case_capability_grants_case_id_fkey FOREIGN KEY (case_id) REFERENCES public.cases(id);


--
-- Name: case_capability_grants case_capability_grants_granted_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.case_capability_grants
    ADD CONSTRAINT case_capability_grants_granted_by_fkey FOREIGN KEY (granted_by) REFERENCES public.users(id);


--
-- Name: case_capability_grants case_capability_grants_grantee_actor_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.case_capability_grants
    ADD CONSTRAINT case_capability_grants_grantee_actor_id_fkey FOREIGN KEY (grantee_actor_id) REFERENCES public.actors(id);


--
-- Name: case_capability_grants case_capability_grants_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.case_capability_grants
    ADD CONSTRAINT case_capability_grants_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id);


--
-- Name: case_documents case_documents_case_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.case_documents
    ADD CONSTRAINT case_documents_case_id_fkey FOREIGN KEY (case_id) REFERENCES public.cases(id);


--
-- Name: case_documents case_documents_created_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.case_documents
    ADD CONSTRAINT case_documents_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.users(id);


--
-- Name: case_documents case_documents_current_version_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.case_documents
    ADD CONSTRAINT case_documents_current_version_fkey FOREIGN KEY (current_version_id) REFERENCES public.document_versions(id);


--
-- Name: case_documents case_documents_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.case_documents
    ADD CONSTRAINT case_documents_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id);


--
-- Name: case_events case_events_hash_chain_fk; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.case_events
    ADD CONSTRAINT case_events_hash_chain_fk FOREIGN KEY (tenant_id, case_id, previous_event_hash) REFERENCES public.case_events(tenant_id, case_id, event_hash) DEFERRABLE;


--
-- Name: case_ingress_submissions case_ingress_submissions_case_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.case_ingress_submissions
    ADD CONSTRAINT case_ingress_submissions_case_id_fkey FOREIGN KEY (case_id) REFERENCES public.cases(id);


--
-- Name: case_ingress_submissions case_ingress_submissions_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.case_ingress_submissions
    ADD CONSTRAINT case_ingress_submissions_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id);


--
-- Name: case_lifecycle_events case_lifecycle_events_case_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.case_lifecycle_events
    ADD CONSTRAINT case_lifecycle_events_case_id_fkey FOREIGN KEY (case_id) REFERENCES public.cases(id);


--
-- Name: case_lifecycle_events case_lifecycle_events_changed_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.case_lifecycle_events
    ADD CONSTRAINT case_lifecycle_events_changed_by_fkey FOREIGN KEY (changed_by) REFERENCES public.users(id);


--
-- Name: case_lifecycle_events case_lifecycle_events_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.case_lifecycle_events
    ADD CONSTRAINT case_lifecycle_events_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id);


--
-- Name: case_parties case_parties_actor_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.case_parties
    ADD CONSTRAINT case_parties_actor_id_fkey FOREIGN KEY (actor_id) REFERENCES public.actors(id);


--
-- Name: case_parties case_parties_case_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.case_parties
    ADD CONSTRAINT case_parties_case_id_fkey FOREIGN KEY (case_id) REFERENCES public.cases(id);


--
-- Name: case_parties case_parties_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.case_parties
    ADD CONSTRAINT case_parties_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id);


--
-- Name: case_party_contacts case_party_contacts_actor_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.case_party_contacts
    ADD CONSTRAINT case_party_contacts_actor_id_fkey FOREIGN KEY (actor_id) REFERENCES public.actors(id);


--
-- Name: case_party_contacts case_party_contacts_case_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.case_party_contacts
    ADD CONSTRAINT case_party_contacts_case_id_fkey FOREIGN KEY (case_id) REFERENCES public.cases(id);


--
-- Name: case_party_contacts case_party_contacts_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.case_party_contacts
    ADD CONSTRAINT case_party_contacts_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id);


--
-- Name: case_prerequisite_compliance_evidence case_prerequisite_compliance_evidence_compliance_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.case_prerequisite_compliance_evidence
    ADD CONSTRAINT case_prerequisite_compliance_evidence_compliance_id_fkey FOREIGN KEY (compliance_id) REFERENCES public.case_prerequisite_compliance(id) ON DELETE CASCADE;


--
-- Name: case_properties case_properties_case_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.case_properties
    ADD CONSTRAINT case_properties_case_id_fkey FOREIGN KEY (case_id) REFERENCES public.cases(id);


--
-- Name: case_properties case_properties_property_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.case_properties
    ADD CONSTRAINT case_properties_property_id_fkey FOREIGN KEY (property_id) REFERENCES public.properties(id);


--
-- Name: case_properties case_properties_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.case_properties
    ADD CONSTRAINT case_properties_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id);


--
-- Name: case_venue_assignments case_venue_assignments_assigned_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.case_venue_assignments
    ADD CONSTRAINT case_venue_assignments_assigned_by_fkey FOREIGN KEY (assigned_by) REFERENCES public.users(id);


--
-- Name: case_venue_assignments case_venue_assignments_case_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.case_venue_assignments
    ADD CONSTRAINT case_venue_assignments_case_id_fkey FOREIGN KEY (case_id) REFERENCES public.cases(id) ON DELETE CASCADE;


--
-- Name: case_venue_assignments case_venue_assignments_court_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.case_venue_assignments
    ADD CONSTRAINT case_venue_assignments_court_id_fkey FOREIGN KEY (court_id) REFERENCES public.courts(id) ON DELETE RESTRICT;


--
-- Name: case_venue_assignments case_venue_assignments_jurisdiction_fk; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.case_venue_assignments
    ADD CONSTRAINT case_venue_assignments_jurisdiction_fk FOREIGN KEY (jurisdiction_unit_id) REFERENCES public.jurisdiction_units(id) ON DELETE RESTRICT;


--
-- Name: case_venue_assignments case_venue_assignments_legal_action_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.case_venue_assignments
    ADD CONSTRAINT case_venue_assignments_legal_action_id_fkey FOREIGN KEY (legal_action_id) REFERENCES public.legal_action_catalog(id) ON DELETE CASCADE;


--
-- Name: case_venue_assignments case_venue_assignments_precinct_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.case_venue_assignments
    ADD CONSTRAINT case_venue_assignments_precinct_id_fkey FOREIGN KEY (precinct_id) REFERENCES public.court_precincts(id) ON DELETE RESTRICT;


--
-- Name: case_venue_overrides case_venue_overrides_jurisdiction_fk; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.case_venue_overrides
    ADD CONSTRAINT case_venue_overrides_jurisdiction_fk FOREIGN KEY (jurisdiction_unit_id) REFERENCES public.jurisdiction_units(id) ON DELETE RESTRICT;


--
-- Name: cases cases_created_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cases
    ADD CONSTRAINT cases_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.users(id);


--
-- Name: cases cases_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cases
    ADD CONSTRAINT cases_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id);


--
-- Name: counties counties_state_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.counties
    ADD CONSTRAINT counties_state_id_fkey FOREIGN KEY (state_id) REFERENCES public.states(id) ON DELETE CASCADE;


--
-- Name: court_filing_locations court_filing_locations_court_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.court_filing_locations
    ADD CONSTRAINT court_filing_locations_court_id_fkey FOREIGN KEY (court_id) REFERENCES public.courts(id) ON DELETE CASCADE;


--
-- Name: court_precincts court_precincts_county_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.court_precincts
    ADD CONSTRAINT court_precincts_county_id_fkey FOREIGN KEY (county_id) REFERENCES public.counties(id) ON DELETE CASCADE;


--
-- Name: court_precincts court_precincts_court_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.court_precincts
    ADD CONSTRAINT court_precincts_court_id_fkey FOREIGN KEY (court_id) REFERENCES public.courts(id) ON DELETE CASCADE;


--
-- Name: courts courts_county_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.courts
    ADD CONSTRAINT courts_county_id_fkey FOREIGN KEY (county_id) REFERENCES public.counties(id) ON DELETE CASCADE;


--
-- Name: courts courts_jurisdiction_unit_fk; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.courts
    ADD CONSTRAINT courts_jurisdiction_unit_fk FOREIGN KEY (jurisdiction_unit_id) REFERENCES public.jurisdiction_units(id) ON DELETE RESTRICT;


--
-- Name: courts courts_state_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.courts
    ADD CONSTRAINT courts_state_id_fkey FOREIGN KEY (state_id) REFERENCES public.states(id) ON DELETE CASCADE;


--
-- Name: demand_delivery_events demand_delivery_events_action_request_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.demand_delivery_events
    ADD CONSTRAINT demand_delivery_events_action_request_id_fkey FOREIGN KEY (action_request_id) REFERENCES public.case_events(id) ON DELETE CASCADE;


--
-- Name: demand_delivery_events demand_delivery_events_case_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.demand_delivery_events
    ADD CONSTRAINT demand_delivery_events_case_id_fkey FOREIGN KEY (case_id) REFERENCES public.cases(id) ON DELETE CASCADE;


--
-- Name: demand_delivery_events demand_delivery_events_delivered_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.demand_delivery_events
    ADD CONSTRAINT demand_delivery_events_delivered_by_fkey FOREIGN KEY (delivered_by) REFERENCES public.users(id);


--
-- Name: demand_delivery_events demand_delivery_events_occupant_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.demand_delivery_events
    ADD CONSTRAINT demand_delivery_events_occupant_fkey FOREIGN KEY (occupant_id) REFERENCES public.case_occupant_action_targets(id);


--
-- Name: demand_delivery_events demand_delivery_events_proof_case_doc_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.demand_delivery_events
    ADD CONSTRAINT demand_delivery_events_proof_case_doc_fkey FOREIGN KEY (proof_case_document_id) REFERENCES public.case_documents(id);


--
-- Name: demand_delivery_events demand_delivery_events_proof_case_document_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.demand_delivery_events
    ADD CONSTRAINT demand_delivery_events_proof_case_document_id_fkey FOREIGN KEY (proof_case_document_id) REFERENCES public.case_documents(id);


--
-- Name: demand_delivery_events demand_delivery_events_proof_document_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.demand_delivery_events
    ADD CONSTRAINT demand_delivery_events_proof_document_id_fkey FOREIGN KEY (proof_document_id) REFERENCES public.documents(id);


--
-- Name: demand_delivery_events demand_delivery_events_service_task_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.demand_delivery_events
    ADD CONSTRAINT demand_delivery_events_service_task_id_fkey FOREIGN KEY (service_task_id) REFERENCES public.service_tasks(id);


--
-- Name: demand_delivery_events demand_delivery_events_target_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.demand_delivery_events
    ADD CONSTRAINT demand_delivery_events_target_fkey FOREIGN KEY (target_id) REFERENCES public.case_occupant_action_targets(id);


--
-- Name: demand_delivery_events demand_delivery_events_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.demand_delivery_events
    ADD CONSTRAINT demand_delivery_events_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: document_capability_grants document_capability_grants_document_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.document_capability_grants
    ADD CONSTRAINT document_capability_grants_document_id_fkey FOREIGN KEY (document_id) REFERENCES public.case_documents(id);


--
-- Name: document_capability_grants document_capability_grants_granted_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.document_capability_grants
    ADD CONSTRAINT document_capability_grants_granted_by_fkey FOREIGN KEY (granted_by) REFERENCES public.users(id);


--
-- Name: document_capability_grants document_capability_grants_grantee_actor_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.document_capability_grants
    ADD CONSTRAINT document_capability_grants_grantee_actor_id_fkey FOREIGN KEY (grantee_actor_id) REFERENCES public.actors(id);


--
-- Name: document_capability_grants document_capability_grants_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.document_capability_grants
    ADD CONSTRAINT document_capability_grants_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id);


--
-- Name: document_events document_events_changed_by_actor_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.document_events
    ADD CONSTRAINT document_events_changed_by_actor_fkey FOREIGN KEY (changed_by) REFERENCES public.actors(id);


--
-- Name: document_events document_events_document_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.document_events
    ADD CONSTRAINT document_events_document_id_fkey FOREIGN KEY (document_id) REFERENCES public.case_documents(id);


--
-- Name: document_events document_events_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.document_events
    ADD CONSTRAINT document_events_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id);


--
-- Name: document_groupings document_groupings_actor_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.document_groupings
    ADD CONSTRAINT document_groupings_actor_id_fkey FOREIGN KEY (actor_id) REFERENCES public.actors(id);


--
-- Name: document_groupings document_groupings_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.document_groupings
    ADD CONSTRAINT document_groupings_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id);


--
-- Name: document_types document_types_created_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.document_types
    ADD CONSTRAINT document_types_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.users(id);


--
-- Name: document_types document_types_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.document_types
    ADD CONSTRAINT document_types_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id);


--
-- Name: document_versions document_versions_document_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.document_versions
    ADD CONSTRAINT document_versions_document_id_fkey FOREIGN KEY (document_id) REFERENCES public.documents(id);


--
-- Name: document_versions document_versions_rendered_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.document_versions
    ADD CONSTRAINT document_versions_rendered_by_fkey FOREIGN KEY (rendered_by) REFERENCES public.users(id);


--
-- Name: document_versions document_versions_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.document_versions
    ADD CONSTRAINT document_versions_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id);


--
-- Name: documents documents_case_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.documents
    ADD CONSTRAINT documents_case_id_fkey FOREIGN KEY (case_id) REFERENCES public.cases(id);


--
-- Name: documents documents_created_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.documents
    ADD CONSTRAINT documents_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.users(id);


--
-- Name: documents documents_filed_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.documents
    ADD CONSTRAINT documents_filed_by_fkey FOREIGN KEY (filed_by) REFERENCES public.users(id);


--
-- Name: documents documents_legal_action_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.documents
    ADD CONSTRAINT documents_legal_action_id_fkey FOREIGN KEY (legal_action_id) REFERENCES public.legal_actions(id);


--
-- Name: documents documents_process_service_event_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.documents
    ADD CONSTRAINT documents_process_service_event_id_fkey FOREIGN KEY (process_service_event_id) REFERENCES public.process_service_events(id);


--
-- Name: documents documents_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.documents
    ADD CONSTRAINT documents_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id);


--
-- Name: evidence_access_grants evidence_access_grants_document_export_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.evidence_access_grants
    ADD CONSTRAINT evidence_access_grants_document_export_id_fkey FOREIGN KEY (document_export_id) REFERENCES public.document_exports(id) ON DELETE RESTRICT;


--
-- Name: evidence_items evidence_items_accepted_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.evidence_items
    ADD CONSTRAINT evidence_items_accepted_by_fkey FOREIGN KEY (accepted_by) REFERENCES public.users(id);


--
-- Name: evidence_items evidence_items_captured_by_actor_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.evidence_items
    ADD CONSTRAINT evidence_items_captured_by_actor_id_fkey FOREIGN KEY (captured_by_actor_id) REFERENCES public.actors(id);


--
-- Name: evidence_items evidence_items_case_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.evidence_items
    ADD CONSTRAINT evidence_items_case_id_fkey FOREIGN KEY (case_id) REFERENCES public.cases(id);


--
-- Name: evidence_items evidence_items_legal_action_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.evidence_items
    ADD CONSTRAINT evidence_items_legal_action_id_fkey FOREIGN KEY (legal_action_id) REFERENCES public.legal_actions(id);


--
-- Name: evidence_items evidence_items_rejected_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.evidence_items
    ADD CONSTRAINT evidence_items_rejected_by_fkey FOREIGN KEY (rejected_by) REFERENCES public.users(id);


--
-- Name: evidence_items evidence_items_service_execution_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.evidence_items
    ADD CONSTRAINT evidence_items_service_execution_id_fkey FOREIGN KEY (service_execution_id) REFERENCES public.service_executions(id);


--
-- Name: evidence_items evidence_items_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.evidence_items
    ADD CONSTRAINT evidence_items_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id);


--
-- Name: exhibit_bundle_workspaces exhibit_bundle_workspaces_case_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.exhibit_bundle_workspaces
    ADD CONSTRAINT exhibit_bundle_workspaces_case_id_fkey FOREIGN KEY (case_id) REFERENCES public.cases(id);


--
-- Name: exhibit_bundle_workspaces exhibit_bundle_workspaces_created_by_actor_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.exhibit_bundle_workspaces
    ADD CONSTRAINT exhibit_bundle_workspaces_created_by_actor_id_fkey FOREIGN KEY (created_by_actor_id) REFERENCES public.actors(id);


--
-- Name: exhibit_bundles exhibit_bundles_case_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.exhibit_bundles
    ADD CONSTRAINT exhibit_bundles_case_id_fkey FOREIGN KEY (case_id) REFERENCES public.cases(id);


--
-- Name: exhibit_bundles exhibit_bundles_created_by_actor_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.exhibit_bundles
    ADD CONSTRAINT exhibit_bundles_created_by_actor_id_fkey FOREIGN KEY (created_by_actor_id) REFERENCES public.actors(id);


--
-- Name: exhibit_bundles exhibit_bundles_filing_document_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.exhibit_bundles
    ADD CONSTRAINT exhibit_bundles_filing_document_id_fkey FOREIGN KEY (filing_document_id) REFERENCES public.documents(id);


--
-- Name: exhibit_workspace_contributions exhibit_workspace_contributions_actor_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.exhibit_workspace_contributions
    ADD CONSTRAINT exhibit_workspace_contributions_actor_id_fkey FOREIGN KEY (actor_id) REFERENCES public.actors(id);


--
-- Name: exhibit_workspace_contributions exhibit_workspace_contributions_source_document_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.exhibit_workspace_contributions
    ADD CONSTRAINT exhibit_workspace_contributions_source_document_id_fkey FOREIGN KEY (source_document_id) REFERENCES public.documents(id);


--
-- Name: exhibit_workspace_contributions exhibit_workspace_contributions_workspace_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.exhibit_workspace_contributions
    ADD CONSTRAINT exhibit_workspace_contributions_workspace_id_fkey FOREIGN KEY (workspace_id) REFERENCES public.exhibit_bundle_workspaces(id) ON DELETE CASCADE;


--
-- Name: exhibit_workspace_participants exhibit_workspace_participants_actor_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.exhibit_workspace_participants
    ADD CONSTRAINT exhibit_workspace_participants_actor_id_fkey FOREIGN KEY (actor_id) REFERENCES public.actors(id);


--
-- Name: exhibit_workspace_participants exhibit_workspace_participants_workspace_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.exhibit_workspace_participants
    ADD CONSTRAINT exhibit_workspace_participants_workspace_id_fkey FOREIGN KEY (workspace_id) REFERENCES public.exhibit_bundle_workspaces(id) ON DELETE CASCADE;


--
-- Name: exhibits exhibits_bundle_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.exhibits
    ADD CONSTRAINT exhibits_bundle_id_fkey FOREIGN KEY (bundle_id) REFERENCES public.exhibit_bundles(id) ON DELETE CASCADE;


--
-- Name: exhibits exhibits_source_document_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.exhibits
    ADD CONSTRAINT exhibits_source_document_id_fkey FOREIGN KEY (source_document_id) REFERENCES public.documents(id);


--
-- Name: case_deadlines fk_case_deadlines_case; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.case_deadlines
    ADD CONSTRAINT fk_case_deadlines_case FOREIGN KEY (case_id) REFERENCES public.cases(id) ON DELETE CASCADE;


--
-- Name: case_events fk_case_events_case; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.case_events
    ADD CONSTRAINT fk_case_events_case FOREIGN KEY (case_id) REFERENCES public.cases(id) ON DELETE CASCADE;


--
-- Name: case_events fk_case_events_previous_hash; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.case_events
    ADD CONSTRAINT fk_case_events_previous_hash FOREIGN KEY (tenant_id, case_id, previous_event_hash) REFERENCES public.case_events(tenant_id, case_id, event_hash) DEFERRABLE INITIALLY DEFERRED;


--
-- Name: case_procedural_deadlines fk_cpd_case; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.case_procedural_deadlines
    ADD CONSTRAINT fk_cpd_case FOREIGN KEY (case_id) REFERENCES public.cases(id) ON DELETE CASCADE;


--
-- Name: legal_actions fk_legal_actions_parent; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.legal_actions
    ADD CONSTRAINT fk_legal_actions_parent FOREIGN KEY (parent_legal_action_id) REFERENCES public.legal_actions(id) DEFERRABLE INITIALLY DEFERRED;


--
-- Name: process_service_event_evidence fk_pse_event; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.process_service_event_evidence
    ADD CONSTRAINT fk_pse_event FOREIGN KEY (process_service_event_id) REFERENCES public.process_service_events(id) ON DELETE CASCADE;


--
-- Name: venue_resolution_audits fk_vra_dataset; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.venue_resolution_audits
    ADD CONSTRAINT fk_vra_dataset FOREIGN KEY (resolved_dataset_id) REFERENCES public.jurisdiction_boundary_datasets(id) ON DELETE RESTRICT;


--
-- Name: judicial_case_access judicial_case_access_case_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.judicial_case_access
    ADD CONSTRAINT judicial_case_access_case_id_fkey FOREIGN KEY (case_id) REFERENCES public.cases(id);


--
-- Name: judicial_case_access judicial_case_access_granted_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.judicial_case_access
    ADD CONSTRAINT judicial_case_access_granted_by_fkey FOREIGN KEY (granted_by) REFERENCES public.users(id);


--
-- Name: judicial_case_access judicial_case_access_judicial_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.judicial_case_access
    ADD CONSTRAINT judicial_case_access_judicial_user_id_fkey FOREIGN KEY (judicial_user_id) REFERENCES public.users(id);


--
-- Name: judicial_case_access judicial_case_access_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.judicial_case_access
    ADD CONSTRAINT judicial_case_access_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id);


--
-- Name: jurisdiction_boundary_ingest_runs jurisdiction_boundary_ingest_runs_dataset_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.jurisdiction_boundary_ingest_runs
    ADD CONSTRAINT jurisdiction_boundary_ingest_runs_dataset_id_fkey FOREIGN KEY (dataset_id) REFERENCES public.jurisdiction_boundary_datasets(id);


--
-- Name: jurisdiction_boundary_update_changes jurisdiction_boundary_update_changes_run_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.jurisdiction_boundary_update_changes
    ADD CONSTRAINT jurisdiction_boundary_update_changes_run_id_fkey FOREIGN KEY (run_id) REFERENCES public.jurisdiction_boundary_update_runs(id) ON DELETE CASCADE;


--
-- Name: jurisdiction_unit_boundaries jurisdiction_unit_boundaries_dataset_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.jurisdiction_unit_boundaries
    ADD CONSTRAINT jurisdiction_unit_boundaries_dataset_id_fkey FOREIGN KEY (dataset_id) REFERENCES public.jurisdiction_boundary_datasets(id);


--
-- Name: jurisdiction_unit_boundaries jurisdiction_unit_boundaries_jurisdiction_unit_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.jurisdiction_unit_boundaries
    ADD CONSTRAINT jurisdiction_unit_boundaries_jurisdiction_unit_id_fkey FOREIGN KEY (jurisdiction_unit_id) REFERENCES public.jurisdiction_units(id) ON DELETE CASCADE;


--
-- Name: jurisdiction_units jurisdiction_units_parent_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.jurisdiction_units
    ADD CONSTRAINT jurisdiction_units_parent_id_fkey FOREIGN KEY (parent_id) REFERENCES public.jurisdiction_units(id) ON DELETE CASCADE;


--
-- Name: justice_courts justice_courts_court_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.justice_courts
    ADD CONSTRAINT justice_courts_court_id_fkey FOREIGN KEY (court_id) REFERENCES public.courts(id) ON DELETE CASCADE;


--
-- Name: justice_courts justice_courts_precinct_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.justice_courts
    ADD CONSTRAINT justice_courts_precinct_id_fkey FOREIGN KEY (precinct_id) REFERENCES public.justice_precincts(id) ON DELETE CASCADE;


--
-- Name: justice_precincts justice_precincts_county_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.justice_precincts
    ADD CONSTRAINT justice_precincts_county_id_fkey FOREIGN KEY (county_id) REFERENCES public.counties(id) ON DELETE CASCADE;


--
-- Name: justice_precincts justice_precincts_state_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.justice_precincts
    ADD CONSTRAINT justice_precincts_state_id_fkey FOREIGN KEY (state_id) REFERENCES public.states(id) ON DELETE CASCADE;


--
-- Name: jwt_revocations jwt_revocations_revoked_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.jwt_revocations
    ADD CONSTRAINT jwt_revocations_revoked_by_fkey FOREIGN KEY (revoked_by) REFERENCES public.users(id);


--
-- Name: jwt_revocations jwt_revocations_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.jwt_revocations
    ADD CONSTRAINT jwt_revocations_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: legal_action_catalog legal_action_catalog_state_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.legal_action_catalog
    ADD CONSTRAINT legal_action_catalog_state_id_fkey FOREIGN KEY (state_id) REFERENCES public.states(id) ON DELETE CASCADE;


--
-- Name: legal_action_delivery_rules legal_action_delivery_rules_delivery_method_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.legal_action_delivery_rules
    ADD CONSTRAINT legal_action_delivery_rules_delivery_method_id_fkey FOREIGN KEY (delivery_method_id) REFERENCES public.delivery_methods(id) ON DELETE RESTRICT;


--
-- Name: legal_action_delivery_rules legal_action_delivery_rules_legal_action_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.legal_action_delivery_rules
    ADD CONSTRAINT legal_action_delivery_rules_legal_action_id_fkey FOREIGN KEY (legal_action_id) REFERENCES public.legal_action_catalog(id) ON DELETE CASCADE;


--
-- Name: legal_action_events legal_action_events_legal_action_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.legal_action_events
    ADD CONSTRAINT legal_action_events_legal_action_id_fkey FOREIGN KEY (legal_action_id) REFERENCES public.legal_actions(id);


--
-- Name: legal_action_events legal_action_events_performed_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.legal_action_events
    ADD CONSTRAINT legal_action_events_performed_by_fkey FOREIGN KEY (performed_by) REFERENCES public.users(id);


--
-- Name: legal_action_events legal_action_events_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.legal_action_events
    ADD CONSTRAINT legal_action_events_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id);


--
-- Name: legal_action_prerequisites legal_action_prerequisites_legal_action_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.legal_action_prerequisites
    ADD CONSTRAINT legal_action_prerequisites_legal_action_id_fkey FOREIGN KEY (legal_action_id) REFERENCES public.legal_action_catalog(id) ON DELETE CASCADE;


--
-- Name: legal_action_step_requirements legal_action_step_requirements_step_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.legal_action_step_requirements
    ADD CONSTRAINT legal_action_step_requirements_step_id_fkey FOREIGN KEY (step_id) REFERENCES public.legal_action_steps(id) ON DELETE CASCADE;


--
-- Name: legal_action_steps legal_action_steps_legal_action_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.legal_action_steps
    ADD CONSTRAINT legal_action_steps_legal_action_id_fkey FOREIGN KEY (legal_action_id) REFERENCES public.legal_action_catalog(id) ON DELETE CASCADE;


--
-- Name: legal_action_title_gates legal_action_title_gates_legal_action_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.legal_action_title_gates
    ADD CONSTRAINT legal_action_title_gates_legal_action_id_fkey FOREIGN KEY (legal_action_id) REFERENCES public.legal_action_catalog(id) ON DELETE CASCADE;


--
-- Name: legal_action_venue_rules legal_action_venue_rules_county_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.legal_action_venue_rules
    ADD CONSTRAINT legal_action_venue_rules_county_id_fkey FOREIGN KEY (county_id) REFERENCES public.counties(id) ON DELETE CASCADE;


--
-- Name: legal_action_venue_rules legal_action_venue_rules_legal_action_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.legal_action_venue_rules
    ADD CONSTRAINT legal_action_venue_rules_legal_action_id_fkey FOREIGN KEY (legal_action_id) REFERENCES public.legal_action_catalog(id) ON DELETE CASCADE;


--
-- Name: legal_actions legal_actions_case_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.legal_actions
    ADD CONSTRAINT legal_actions_case_id_fkey FOREIGN KEY (case_id) REFERENCES public.cases(id);


--
-- Name: legal_actions legal_actions_created_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.legal_actions
    ADD CONSTRAINT legal_actions_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.users(id);


--
-- Name: legal_actions legal_actions_filing_attorney_actor_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.legal_actions
    ADD CONSTRAINT legal_actions_filing_attorney_actor_id_fkey FOREIGN KEY (filing_attorney_actor_id) REFERENCES public.actors(id) ON DELETE SET NULL;


--
-- Name: legal_actions legal_actions_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.legal_actions
    ADD CONSTRAINT legal_actions_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id);


--
-- Name: outbound_messages outbound_messages_created_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.outbound_messages
    ADD CONSTRAINT outbound_messages_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.users(id);


--
-- Name: outbound_messages outbound_messages_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.outbound_messages
    ADD CONSTRAINT outbound_messages_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id);


--
-- Name: process_service_events process_service_events_affidavit_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.process_service_events
    ADD CONSTRAINT process_service_events_affidavit_fkey FOREIGN KEY (affidavit_document_id) REFERENCES public.documents(id);


--
-- Name: process_service_events process_service_events_case_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.process_service_events
    ADD CONSTRAINT process_service_events_case_id_fkey FOREIGN KEY (case_id) REFERENCES public.cases(id);


--
-- Name: process_service_events process_service_events_created_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.process_service_events
    ADD CONSTRAINT process_service_events_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.users(id);


--
-- Name: process_service_events process_service_events_property_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.process_service_events
    ADD CONSTRAINT process_service_events_property_id_fkey FOREIGN KEY (property_id) REFERENCES public.properties(id);


--
-- Name: process_service_events process_service_events_served_actor_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.process_service_events
    ADD CONSTRAINT process_service_events_served_actor_id_fkey FOREIGN KEY (served_actor_id) REFERENCES public.actors(id);


--
-- Name: process_service_events process_service_events_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.process_service_events
    ADD CONSTRAINT process_service_events_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id);


--
-- Name: properties properties_owner_actor_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.properties
    ADD CONSTRAINT properties_owner_actor_fkey FOREIGN KEY (owner_actor_id) REFERENCES public.actors(id);


--
-- Name: properties properties_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.properties
    ADD CONSTRAINT properties_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id);


--
-- Name: property_image_derivatives property_image_derivatives_image_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.property_image_derivatives
    ADD CONSTRAINT property_image_derivatives_image_id_fkey FOREIGN KEY (image_id) REFERENCES public.property_images(id) ON DELETE CASCADE;


--
-- Name: property_images property_images_property_fk; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.property_images
    ADD CONSTRAINT property_images_property_fk FOREIGN KEY (property_id) REFERENCES public.properties(id) ON DELETE CASCADE;


--
-- Name: property_images property_images_tenant_fk; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.property_images
    ADD CONSTRAINT property_images_tenant_fk FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: property_jurisdiction_assignments property_jurisdiction_assignments_boundary_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.property_jurisdiction_assignments
    ADD CONSTRAINT property_jurisdiction_assignments_boundary_id_fkey FOREIGN KEY (boundary_id) REFERENCES public.jurisdiction_unit_boundaries(id);


--
-- Name: property_jurisdiction_assignments property_jurisdiction_assignments_jurisdiction_unit_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.property_jurisdiction_assignments
    ADD CONSTRAINT property_jurisdiction_assignments_jurisdiction_unit_id_fkey FOREIGN KEY (jurisdiction_unit_id) REFERENCES public.jurisdiction_units(id);


--
-- Name: property_jurisdiction_assignments property_jurisdiction_assignments_property_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.property_jurisdiction_assignments
    ADD CONSTRAINT property_jurisdiction_assignments_property_id_fkey FOREIGN KEY (property_id) REFERENCES public.properties(id);


--
-- Name: property_relationships property_relationships_actor_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.property_relationships
    ADD CONSTRAINT property_relationships_actor_id_fkey FOREIGN KEY (actor_id) REFERENCES public.actors(id);


--
-- Name: property_relationships property_relationships_created_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.property_relationships
    ADD CONSTRAINT property_relationships_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.users(id);


--
-- Name: property_relationships property_relationships_property_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.property_relationships
    ADD CONSTRAINT property_relationships_property_id_fkey FOREIGN KEY (property_id) REFERENCES public.properties(id);


--
-- Name: property_relationships property_relationships_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.property_relationships
    ADD CONSTRAINT property_relationships_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id);


--
-- Name: property_supersession_events property_supersession_events_created_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.property_supersession_events
    ADD CONSTRAINT property_supersession_events_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.users(id);


--
-- Name: property_supersession_events property_supersession_events_new_property_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.property_supersession_events
    ADD CONSTRAINT property_supersession_events_new_property_id_fkey FOREIGN KEY (new_property_id) REFERENCES public.properties(id);


--
-- Name: property_supersession_events property_supersession_events_prior_property_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.property_supersession_events
    ADD CONSTRAINT property_supersession_events_prior_property_id_fkey FOREIGN KEY (prior_property_id) REFERENCES public.properties(id);


--
-- Name: property_supersession_events property_supersession_events_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.property_supersession_events
    ADD CONSTRAINT property_supersession_events_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id);


--
-- Name: purchaser_entities purchaser_entities_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.purchaser_entities
    ADD CONSTRAINT purchaser_entities_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: role_scopes role_scopes_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.role_scopes
    ADD CONSTRAINT role_scopes_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: roles roles_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.roles
    ADD CONSTRAINT roles_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id);


--
-- Name: secure_access_links secure_access_links_case_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.secure_access_links
    ADD CONSTRAINT secure_access_links_case_id_fkey FOREIGN KEY (case_id) REFERENCES public.cases(id);


--
-- Name: secure_access_links secure_access_links_document_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.secure_access_links
    ADD CONSTRAINT secure_access_links_document_id_fkey FOREIGN KEY (document_id) REFERENCES public.documents(id);


--
-- Name: secure_access_links secure_access_links_issued_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.secure_access_links
    ADD CONSTRAINT secure_access_links_issued_by_fkey FOREIGN KEY (issued_by) REFERENCES public.users(id);


--
-- Name: secure_access_links secure_access_links_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.secure_access_links
    ADD CONSTRAINT secure_access_links_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id);


--
-- Name: service_executions service_executions_case_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.service_executions
    ADD CONSTRAINT service_executions_case_id_fkey FOREIGN KEY (case_id) REFERENCES public.cases(id);


--
-- Name: service_executions service_executions_created_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.service_executions
    ADD CONSTRAINT service_executions_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.users(id);


--
-- Name: service_executions service_executions_legal_action_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.service_executions
    ADD CONSTRAINT service_executions_legal_action_id_fkey FOREIGN KEY (legal_action_id) REFERENCES public.legal_actions(id);


--
-- Name: service_executions service_executions_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.service_executions
    ADD CONSTRAINT service_executions_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id);


--
-- Name: tenant_client_profiles tenant_client_profiles_client_actor_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tenant_client_profiles
    ADD CONSTRAINT tenant_client_profiles_client_actor_id_fkey FOREIGN KEY (client_actor_id) REFERENCES public.actors(id);


--
-- Name: tenant_client_profiles tenant_client_profiles_created_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tenant_client_profiles
    ADD CONSTRAINT tenant_client_profiles_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.users(id);


--
-- Name: tenant_client_profiles tenant_client_profiles_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tenant_client_profiles
    ADD CONSTRAINT tenant_client_profiles_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id);


--
-- Name: tenant_event_type_permissions tenant_event_type_permissions_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tenant_event_type_permissions
    ADD CONSTRAINT tenant_event_type_permissions_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: tenant_invitations tenant_invitations_issued_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tenant_invitations
    ADD CONSTRAINT tenant_invitations_issued_by_fkey FOREIGN KEY (issued_by) REFERENCES public.users(id);


--
-- Name: tenant_invitations tenant_invitations_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tenant_invitations
    ADD CONSTRAINT tenant_invitations_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: tenant_phase_permissions tenant_phase_permissions_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tenant_phase_permissions
    ADD CONSTRAINT tenant_phase_permissions_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: tenant_properties tenant_properties_added_by_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tenant_properties
    ADD CONSTRAINT tenant_properties_added_by_user_id_fkey FOREIGN KEY (added_by_user_id) REFERENCES public.users(id);


--
-- Name: tenant_properties tenant_properties_legacy_owner_actor_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tenant_properties
    ADD CONSTRAINT tenant_properties_legacy_owner_actor_id_fkey FOREIGN KEY (legacy_owner_actor_id) REFERENCES public.actors(id);


--
-- Name: tenant_properties tenant_properties_property_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tenant_properties
    ADD CONSTRAINT tenant_properties_property_id_fkey FOREIGN KEY (property_id) REFERENCES public.properties(id);


--
-- Name: tenant_properties tenant_properties_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tenant_properties
    ADD CONSTRAINT tenant_properties_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id);


--
-- Name: tenant_timeline_policies tenant_timeline_policies_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tenant_timeline_policies
    ADD CONSTRAINT tenant_timeline_policies_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: title_events title_events_case_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.title_events
    ADD CONSTRAINT title_events_case_id_fkey FOREIGN KEY (case_id) REFERENCES public.cases(id) ON DELETE CASCADE;


--
-- Name: title_events title_events_county_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.title_events
    ADD CONSTRAINT title_events_county_id_fkey FOREIGN KEY (county_id) REFERENCES public.counties(id) ON DELETE RESTRICT;


--
-- Name: title_events title_events_created_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.title_events
    ADD CONSTRAINT title_events_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.users(id);


--
-- Name: title_events title_events_property_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.title_events
    ADD CONSTRAINT title_events_property_id_fkey FOREIGN KEY (property_id) REFERENCES public.properties(id) ON DELETE CASCADE;


--
-- Name: title_events title_events_purchaser_entity_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.title_events
    ADD CONSTRAINT title_events_purchaser_entity_id_fkey FOREIGN KEY (purchaser_entity_id) REFERENCES public.purchaser_entities(id) ON DELETE RESTRICT;


--
-- Name: title_events title_events_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.title_events
    ADD CONSTRAINT title_events_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: trustee_deed_events trustee_deed_events_case_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.trustee_deed_events
    ADD CONSTRAINT trustee_deed_events_case_id_fkey FOREIGN KEY (case_id) REFERENCES public.cases(id) ON DELETE CASCADE;


--
-- Name: trustee_deed_events trustee_deed_events_deed_document_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.trustee_deed_events
    ADD CONSTRAINT trustee_deed_events_deed_document_id_fkey FOREIGN KEY (deed_document_id) REFERENCES public.documents(id);


--
-- Name: trustee_deed_events trustee_deed_events_property_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.trustee_deed_events
    ADD CONSTRAINT trustee_deed_events_property_id_fkey FOREIGN KEY (property_id) REFERENCES public.properties(id) ON DELETE CASCADE;


--
-- Name: trustee_deed_events trustee_deed_events_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.trustee_deed_events
    ADD CONSTRAINT trustee_deed_events_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: trustee_deed_events trustee_deed_events_trustee_sale_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.trustee_deed_events
    ADD CONSTRAINT trustee_deed_events_trustee_sale_id_fkey FOREIGN KEY (trustee_sale_id) REFERENCES public.trustee_sale_events(id) ON DELETE CASCADE;


--
-- Name: trustee_sale_events trustee_sale_events_case_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.trustee_sale_events
    ADD CONSTRAINT trustee_sale_events_case_id_fkey FOREIGN KEY (case_id) REFERENCES public.cases(id) ON DELETE CASCADE;


--
-- Name: trustee_sale_events trustee_sale_events_property_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.trustee_sale_events
    ADD CONSTRAINT trustee_sale_events_property_id_fkey FOREIGN KEY (property_id) REFERENCES public.properties(id) ON DELETE CASCADE;


--
-- Name: trustee_sale_events trustee_sale_events_purchaser_entity_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.trustee_sale_events
    ADD CONSTRAINT trustee_sale_events_purchaser_entity_id_fkey FOREIGN KEY (purchaser_entity_id) REFERENCES public.purchaser_entities(id) ON DELETE CASCADE;


--
-- Name: trustee_sale_events trustee_sale_events_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.trustee_sale_events
    ADD CONSTRAINT trustee_sale_events_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: user_credentials user_credentials_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_credentials
    ADD CONSTRAINT user_credentials_user_id_fkey FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: user_roles user_roles_role_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_roles
    ADD CONSTRAINT user_roles_role_id_fkey FOREIGN KEY (role_id) REFERENCES public.roles(id);


--
-- Name: user_roles user_roles_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_roles
    ADD CONSTRAINT user_roles_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id);


--
-- Name: user_roles user_roles_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_roles
    ADD CONSTRAINT user_roles_user_id_fkey FOREIGN KEY (user_id) REFERENCES public.users(id);


--
-- Name: users users_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id);


--
-- Name: venue_resolution_audits venue_resolution_audits_resolved_boundary_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.venue_resolution_audits
    ADD CONSTRAINT venue_resolution_audits_resolved_boundary_id_fkey FOREIGN KEY (resolved_boundary_id) REFERENCES public.jurisdiction_unit_boundaries(id);


--
-- Name: venue_rules venue_rules_state_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.venue_rules
    ADD CONSTRAINT venue_rules_state_id_fkey FOREIGN KEY (state_id) REFERENCES public.states(id) ON DELETE CASCADE;


--
-- PostgreSQL database dump complete
--

