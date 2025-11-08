-- php/tools/install_triggers.sql
-- Assumim MySQL 8.0+ i taula `Riders` amb camps:
-- Estat_Segell ENUM('cap','pendent','validat','caducat')
-- Data_Publicacio DATETIME NULL
-- rider_actualitzat INT NULL REFERENCES Riders(ID_Rider)

DELIMITER //

-- 1) BEFORE INSERT: forcem coherència Data_Publicacio segons Estat_Segell
DROP TRIGGER IF EXISTS riders_bi_segell //
CREATE TRIGGER riders_bi_segell
BEFORE INSERT ON Riders
FOR EACH ROW
BEGIN
  -- Si entra com 'validat' i no porta Data_Publicacio → ara
  IF NEW.Estat_Segell = 'validat' THEN
    IF NEW.Data_Publicacio IS NULL THEN
      SET NEW.Data_Publicacio = NOW();
    END IF;
  ELSE
    -- Qualsevol altre estat no pot tenir Data_Publicacio
    SET NEW.Data_Publicacio = NULL;
  END IF;

  -- Opcional: només té sentit redirecció si està 'caducat'
  IF NEW.Estat_Segell <> 'caducat' THEN
    SET NEW.rider_actualitzat = NULL;
  END IF;
END //
  
-- 2) BEFORE UPDATE: mantenim la coherència quan canvia el segell
DROP TRIGGER IF EXISTS riders_bu_segell //
CREATE TRIGGER riders_bu_segell
BEFORE UPDATE ON Riders
FOR EACH ROW
BEGIN
  IF NEW.Estat_Segell = 'validat' THEN
    -- Quan passa a 'validat' per primera vegada o li netegen la data → posem NOW()
    IF (OLD.Estat_Segell <> 'validat') OR NEW.Data_Publicacio IS NULL THEN
      SET NEW.Data_Publicacio = NOW();
    END IF;
  ELSE
    -- En qualsevol altre estat, Data_Publicacio ha de ser NULL
    SET NEW.Data_Publicacio = NULL;
  END IF;

  -- Opcional: només permetem redirecció si està 'caducat'
  IF NEW.Estat_Segell <> 'caducat' THEN
    SET NEW.rider_actualitzat = NULL;
  END IF;
END //
DELIMITER ;

-- 3) CHECK constraints (si el teu MySQL els aplica)
--   a) Data_Publicacio coherent amb Estat_Segell
ALTER TABLE Riders
  DROP CHECK IF EXISTS chk_segell_pub,
  ADD CONSTRAINT chk_segell_pub
  CHECK (
    (Estat_Segell = 'validat' AND Data_Publicacio IS NOT NULL)
    OR
    (Estat_Segell <> 'validat' AND Data_Publicacio IS NULL)
  );

--   b) Redirecció només si 'caducat' (si tens el flux així definit)
ALTER TABLE Riders
  DROP CHECK IF EXISTS chk_redirect_caducat,
  ADD CONSTRAINT chk_redirect_caducat
  CHECK (
    (Estat_Segell = 'caducat' AND rider_actualitzat IS NOT NULL)
    OR
    (Estat_Segell <> 'caducat' AND rider_actualitzat IS NULL)
  );